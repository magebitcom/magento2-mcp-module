<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * MCP tool `system.config.set` — write one `core_config_data` path. Four gates must pass: the write
 * policy, the sensitivity guard, the target section's own admin ACL, and every check the writer makes.
 */
class ConfigSet implements ToolInterface, UnderlyingAclAwareInterface, ContextualAclAwareInterface
{
    public const TOOL_NAME = 'system.config.set';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_config_set';

    /**
     * @param ConfigWritePolicy $writePolicy
     * @param SensitiveFieldGuard $sensitiveFieldGuard
     * @param ConfigPathWriter $pathWriter
     */
    public function __construct(
        private readonly ConfigWritePolicy $writePolicy,
        private readonly SensitiveFieldGuard $sensitiveFieldGuard,
        private readonly ConfigPathWriter $pathWriter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Set System Configuration Value';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Write a single Magento system-configuration value by its '
            . 'slash-separated path. Only paths an administrator has added to '
            . 'the MCP Configuration Writer allowlist can be written, and a '
            . 'protected set (admin, payment, web URLs, system, dev, oauth and '
            . 'the MCP module\'s own settings) is refused regardless of the '
            . 'allowlist. Paths with no field in system.xml are refused — if '
            . 'the admin UI cannot set it, neither can this tool. That excludes '
            . 'the whole `design/*` family, which lives in Content > Design > '
            . 'Configuration rather than in system.xml, and any field that takes '
            . 'a file upload.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->pattern('^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){2,}$')
                ->description('Slash-separated config path (section/group/field).')
                ->required())
            ->string('value', fn (StringBuilder $s) => $s
                ->description('New value. Booleans are `0` / `1`; the field\'s '
                    . 'backend model coerces and validates it.')
                ->required())
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['default', 'websites', 'stores'])
                ->description('Scope level. Defaults to `default`.'))
            ->string('scope_code', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Website / store code when `scope` is `websites` or `stores`.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_Config::config';
    }

    /**
     * @inheritDoc
     */
    public function getContextualAclResources(array $arguments): array
    {
        $rawPath = $arguments['path'] ?? null;
        if (!is_string($rawPath) || $rawPath === '') {
            return [];
        }

        $resource = $this->sectionResource($this->pathWriter->sectionFor($rawPath));

        return $resource === null ? [] : [$resource];
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $path = $this->requireString($arguments, 'path');
        $value = $this->requireString($arguments, 'value');

        $rawScope = $arguments['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scope = $this->resolveScopeType(is_string($rawScope) ? $rawScope : '');

        $rawScopeCode = $arguments['scope_code'] ?? null;
        $scopeCode = is_string($rawScopeCode) && $rawScopeCode !== '' ? $rawScopeCode : null;

        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $scopeCode === null) {
            throw new LocalizedException(
                __('Parameter "scope_code" is required when "scope" is "websites" or "stores".')
            );
        }

        $this->writePolicy->assertWritable($path);

        $reason = $this->sensitiveFieldGuard->rejectionFor($path);
        if ($reason !== null) {
            throw new LocalizedException(
                __('Path "%1" cannot be written over MCP (%2). Use the admin UI or CLI.', $path, $reason)
            );
        }

        // A section with no <resource> is refused by Stores > Configuration for every role, and the
        // contextual gate reads [] as "no gate", so that refusal has to be made here.
        $section = $this->pathWriter->sectionFor($path);
        if ($section !== null && $this->sectionResource($section) === null) {
            throw new LocalizedException(
                __(
                    'Path "%1" is in a configuration section that declares no admin permission, which '
                    . 'the admin UI refuses to save for every role. This tool refuses it too.',
                    $path
                )
            );
        }

        $previous = $this->pathWriter->currentValue($path, $scope, $scopeCode);
        $this->pathWriter->write($path, $value, $scope, $scopeCode);

        $payload = [
            'path' => $path,
            'scope' => $scope,
            'scope_code' => $scopeCode,
            'previous_value' => $previous,
            'value' => $value,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode config write result as JSON.'));
        }

        // The audit row is the only undo trail a config change gets, so the delta belongs in it.
        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: $payload
        );
    }

    /**
     * The admin resource guarding the section the row lands in, or null when there is none to check.
     *
     * @param Section|null $section
     * @return string|null
     */
    private function sectionResource(?Section $section): ?string
    {
        $resource = $section?->getAttribute('resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    private function requireString(array $arguments, string $key): string
    {
        $raw = $arguments[$key] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new LocalizedException(__('Parameter "%1" is required.', $key));
        }

        return $raw;
    }

    /**
     * @param string $scope
     * @return string
     * @throws LocalizedException
     */
    private function resolveScopeType(string $scope): string
    {
        return match ($scope) {
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            ScopeInterface::SCOPE_WEBSITES => ScopeInterface::SCOPE_WEBSITES,
            ScopeInterface::SCOPE_STORES => ScopeInterface::SCOPE_STORES,
            default => throw new LocalizedException(
                __('Unsupported scope "%1". Expected "default", "websites", or "stores".', $scope)
            ),
        };
    }
}
