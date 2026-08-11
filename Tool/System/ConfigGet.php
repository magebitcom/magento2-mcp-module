<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * MCP tool `system.config.get` — read a single `core_config_data` path.
 * Rejects encrypted / `password` / `obscure` system.xml fields and, for
 * paths without a system.xml entry, any that hit a sensitive-keyword
 * blocklist; rejected reads return `forbidden=true` instead of the value.
 */
class ConfigGet implements ToolInterface
{
    public const TOOL_NAME = 'system.config.get';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_config_get';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SensitiveFieldGuard $sensitiveFieldGuard
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SensitiveFieldGuard $sensitiveFieldGuard
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
        return 'Get System Configuration Value';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Fetch a single Magento system-configuration value by its '
            . 'slash-separated path (e.g. `general/store_information/name` '
            . 'or `web/secure/base_url`). Optionally scope the read to a '
            . 'specific website or store. Encrypted / password-backed '
            . 'fields are refused with a `FORBIDDEN_FIELD` marker — use '
            . 'the admin UI or CLI for those.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->pattern('^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){2,}$')
                ->description('Slash-separated config path (section/group/field; '
                    . 'deeper nesting allowed for modules with nested groups).')
                ->required())
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['default', 'websites', 'stores'])
                ->description('Scope level. Defaults to `default`. '
                    . 'Supply `scope_code` with `websites` / `stores`.'))
            ->string('scope_code', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Website / store code when `scope` is '
                    . '`websites` or `stores`.'))
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
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $rawPath = $arguments['path'] ?? '';
        $path = is_string($rawPath) ? $rawPath : '';
        if ($path === '') {
            throw new LocalizedException(__('Parameter "path" is required.'));
        }

        $rawScope = $arguments['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scope = $this->resolveScopeType(is_string($rawScope) ? $rawScope : '');

        $rawScopeCode = $arguments['scope_code'] ?? null;
        $scopeCode = is_string($rawScopeCode) && $rawScopeCode !== '' ? $rawScopeCode : null;

        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $scopeCode === null) {
            throw new LocalizedException(
                __('Parameter "scope_code" is required when "scope" is "websites" or "stores".')
            );
        }

        $reason = $this->sensitiveFieldGuard->rejectionFor($path);
        if ($reason !== null) {
            return new ToolResult(
                content: [['type' => 'text', 'text' => json_encode([
                    'path' => $path,
                    'scope' => $scope,
                    'scope_code' => $scopeCode,
                    'value' => null,
                    'forbidden' => true,
                    'reason' => $reason,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}']],
                auditSummary: [
                    'path' => $path,
                    'scope' => $scope,
                    'scope_code' => $scopeCode,
                    'forbidden' => true,
                    'reason' => $reason,
                ]
            );
        }

        $value = $this->scopeConfig->getValue($path, $scope, $scopeCode);

        if (is_array($value)) {
            return new ToolResult(
                content: [['type' => 'text', 'text' => json_encode([
                    'path' => $path,
                    'scope' => $scope,
                    'scope_code' => $scopeCode,
                    'value' => null,
                    'forbidden' => true,
                    'reason' => 'non_leaf_value',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}']],
                auditSummary: [
                    'path' => $path,
                    'scope' => $scope,
                    'scope_code' => $scopeCode,
                    'forbidden' => true,
                    'reason' => 'non_leaf_value',
                ]
            );
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => json_encode([
                'path' => $path,
                'scope' => $scope,
                'scope_code' => $scopeCode,
                'value' => $this->normalizeValue($value),
                'forbidden' => false,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}']],
            auditSummary: [
                'path' => $path,
                'scope' => $scope,
                'scope_code' => $scopeCode,
            ]
        );
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

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return null;
    }
}
