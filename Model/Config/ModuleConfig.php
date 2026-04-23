<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reader for every `magebit_mcp/*` store-config value.
 *
 * Centralizing access here means system.xml is the single source of truth —
 * DI-time lists in etc/di.xml would otherwise force a compile after every
 * admin change. `ScopeConfigInterface` honours the `default` scope that
 * system.xml declares, so values edited in Stores → Configuration take
 * effect on the next cached-config reload (or immediately after a
 * `cache:clean config` flush).
 */
class ModuleConfig
{
    public const XML_PATH_ENABLED = 'magebit_mcp/general/enabled';
    public const XML_PATH_ALLOW_WRITES = 'magebit_mcp/general/allow_writes';
    public const XML_PATH_ALLOWED_ORIGINS = 'magebit_mcp/security/allowed_origins';
    public const XML_PATH_AUDIT_RETENTION_DAYS = 'magebit_mcp/audit/retention_days';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isAllowWrites(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ALLOW_WRITES);
    }

    /**
     * Parsed allowlist — one origin per line, `#` comments and blank lines
     * stripped. Trailing `*` wildcards are handled by {@see \Magebit\Mcp\Model\Validator\OriginValidator}.
     *
     * @return array<int, string>
     */
    public function getAllowedOrigins(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_ORIGINS);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $origins = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $origins[] = $line;
        }
        return $origins;
    }

    public function getAuditRetentionDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_AUDIT_RETENTION_DAYS);
        return is_scalar($value) ? max(0, (int) $value) : 0;
    }
}
