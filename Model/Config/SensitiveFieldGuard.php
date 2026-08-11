<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;

/**
 * Decides whether a config path may be exposed at all. Shared by the read and
 * write tools so the two can never drift on what counts as sensitive.
 */
class SensitiveFieldGuard
{
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
     * @param Structure $configStructure
     * @param array<array-key, string> $blockedPrefixes Segment-boundary matched, so `magebit_mcp`
     *        blocks `magebit_mcp/tools/disabled` without catching `magebit_mcpx/...`.
     */
    public function __construct(
        private readonly Structure $configStructure,
        private readonly array $blockedPrefixes = []
    ) {
    }

    /**
     * @param string $path
     * @return string|null Rejection reason, or null when the path is permitted.
     */
    public function rejectionFor(string $path): ?string
    {
        if ($this->pathMatchesBlockedPrefix($path)) {
            return 'path_prefix_blocked';
        }

        $field = $this->configStructure->getElementByConfigPath($path);

        if ($field instanceof Field) {
            $type = strtolower((string) $field->getType());
            if (in_array($type, self::SENSITIVE_FIELD_TYPES, true)) {
                return 'field_type_sensitive';
            }

            $backend = $field->getAttribute('backend_model');
            if (is_string($backend) && $backend !== '' && $this->isEncryptedBackend($backend)) {
                return 'encrypted_backend_model';
            }
        } elseif ($field !== null) {
            // Group / Section / Tab elements collapse to a sub-tree, bypassing the
            // per-field checks above. Refuse them.
            return 'non_field_path';
        }

        if ($this->pathMatchesSensitiveKeyword($path)) {
            return 'path_keyword_blocked';
        }

        return null;
    }

    /**
     * @param string $className
     * @return bool
     */
    private function isEncryptedBackend(string $className): bool
    {
        $normalized = ltrim($className, '\\');
        return $normalized === Encrypted::class || is_subclass_of($normalized, Encrypted::class);
    }

    /**
     * Mirrors ConfigWritePolicy's protected prefixes on the read side: the module's own
     * settings must not be readable back out through the tool they gate.
     *
     * @param string $path
     * @return bool
     */
    private function pathMatchesBlockedPrefix(string $path): bool
    {
        $normalized = strtolower(trim($path, " \t\n\r\0\x0B/"));

        foreach ($this->blockedPrefixes as $prefix) {
            $candidate = strtolower(trim($prefix, " \t\n\r\0\x0B/"));
            if ($candidate === '') {
                continue;
            }
            if ($normalized === $candidate || str_starts_with($normalized, $candidate . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
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
}
