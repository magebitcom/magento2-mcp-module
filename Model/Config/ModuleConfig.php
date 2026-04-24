<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
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
    public const XML_PATH_SERVER_NAME = 'magebit_mcp/general/server_name';
    public const XML_PATH_SERVER_DESCRIPTION = 'magebit_mcp/general/server_description';
    public const XML_PATH_ALLOW_WRITES = 'magebit_mcp/general/allow_writes';
    public const XML_PATH_ALLOWED_ORIGINS = 'magebit_mcp/security/allowed_origins';
    public const XML_PATH_AUDIT_RETENTION_DAYS = 'magebit_mcp/audit/retention_days';

    public const DEFAULT_SERVER_NAME = 'Magento MCP';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * True when the master kill-switch allows traffic through `POST /mcp`.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * True when global-config permits WRITE-mode tools (tokens must also opt in).
     *
     * @return bool
     */
    public function isAllowWrites(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ALLOW_WRITES);
    }

    /**
     * Server name advertised to MCP clients via `initialize.serverInfo.name`.
     *
     * Falls back to {@see self::DEFAULT_SERVER_NAME} when the admin leaves the
     * field blank so clients never see an empty identifier.
     *
     * @return string
     */
    public function getServerName(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SERVER_NAME);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : self::DEFAULT_SERVER_NAME;
    }

    /**
     * Optional free-text description surfaced via `initialize.instructions`.
     *
     * @return string|null
     */
    public function getServerDescription(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SERVER_DESCRIPTION);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * Parsed allowlist — one origin per line, `#` comments and blank lines
     * stripped. Trailing `*` wildcards are handled by
     * {@see \Magebit\Mcp\Model\Validator\OriginValidator}.
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

    /**
     * Retention window (in days) applied by the audit-log purge cron.
     *
     * @return int
     */
    public function getAuditRetentionDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_AUDIT_RETENTION_DAYS);
        return is_scalar($value) ? max(0, (int) $value) : 0;
    }
}
