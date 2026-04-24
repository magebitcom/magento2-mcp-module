<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * MCP tool `system.config.get` — read a single `core_config_data` path.
 *
 * Hard-gated against leaking secrets:
 *
 * 1. If the path resolves to a system.xml {@see Field}, reject whenever
 *    its backend model inherits from {@see Encrypted} or its form type is
 *    `password` / `obscure`.
 * 2. If the path has no system.xml definition, fall back to a keyword
 *    blocklist (`password`, `api_key`, `token`, `secret`, etc.) so
 *    config-only values (no admin UI) can't be exfiltrated either.
 *
 * A rejected read returns `FORBIDDEN_FIELD` rather than the raw value —
 * audit-log callers see the attempt but never the payload.
 */
class ConfigGet implements ToolInterface
{
    public const TOOL_NAME = 'system.config.get';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_config_get';

    private const SENSITIVE_PATH_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'private_key',
        'privatekey',
        'api_key',
        'apikey',
        'auth_token',
        'authtoken',
        'access_token',
        'accesstoken',
        'client_secret',
        'encryption',
        'encrypted',
        'signature',
        'webhook_secret',
    ];

    private const SENSITIVE_FIELD_TYPES = ['password', 'obscure'];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Structure $configStructure
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Structure $configStructure
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
                ->pattern('^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){1,}$')
                ->description('Slash-separated config path (section/group/field).')
                ->required()
            )
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['default', 'websites', 'stores'])
                ->description('Scope level. Defaults to `default`. '
                    . 'Supply `scope_code` with `websites` / `stores`.')
            )
            ->string('scope_code', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Website / store code when `scope` is '
                    . '`websites` or `stores`.')
            )
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

        [$forbidden, $reason] = $this->fieldRejection($path);
        if ($forbidden) {
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
     * Normalize the MCP-caller scope keyword to the ScopeConfig constant.
     *
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
     * Decide whether a given path is read-forbidden.
     *
     * Returns [forbidden, reason].
     *
     * @param string $path
     * @return array{0: bool, 1: string|null}
     */
    private function fieldRejection(string $path): array
    {
        $field = $this->configStructure->getElementByConfigPath($path);

        if ($field instanceof Field) {
            $type = strtolower((string) $field->getType());
            if (in_array($type, self::SENSITIVE_FIELD_TYPES, true)) {
                return [true, 'field_type_sensitive'];
            }

            $backend = $field->getAttribute('backend_model');
            if (is_string($backend) && $backend !== '' && $this->isEncryptedBackend($backend)) {
                return [true, 'encrypted_backend_model'];
            }
        }

        if ($this->pathMatchesSensitiveKeyword($path)) {
            return [true, 'path_keyword_blocked'];
        }

        return [false, null];
    }

    /**
     * Whether the declared backend_model is an Encrypted value type.
     *
     * @param string $className
     * @return bool
     */
    private function isEncryptedBackend(string $className): bool
    {
        $normalized = ltrim($className, '\\');
        if ($normalized === Encrypted::class || is_subclass_of($normalized, Encrypted::class)) {
            return true;
        }
        return false;
    }

    /**
     * Conservative fallback when the path has no system.xml entry.
     *
     * @param string $path
     * @return bool
     */
    private function pathMatchesSensitiveKeyword(string $path): bool
    {
        $needle = strtolower($path);
        foreach (self::SENSITIVE_PATH_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collapse scalars + arrays to JSON-encodable primitives.
     *
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
