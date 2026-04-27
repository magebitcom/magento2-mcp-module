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
 */
class ModuleConfig
{
    public const XML_PATH_ENABLED = 'magebit_mcp/general/enabled';
    public const XML_PATH_SERVER_NAME = 'magebit_mcp/general/server_name';
    public const XML_PATH_SERVER_DESCRIPTION = 'magebit_mcp/general/server_description';
    public const XML_PATH_ALLOW_WRITES = 'magebit_mcp/general/allow_writes';
    public const XML_PATH_ALLOWED_ORIGINS = 'magebit_mcp/security/allowed_origins';
    public const XML_PATH_AUDIT_RETENTION_DAYS = 'magebit_mcp/audit/retention_days';
    public const XML_PATH_RATE_LIMITING_ENABLED = 'magebit_mcp/rate_limiting/enabled';
    public const XML_PATH_RATE_LIMITING_RPM = 'magebit_mcp/rate_limiting/requests_per_minute';
    public const XML_PATH_OAUTH_AUTH_CODE_LIFETIME = 'magebit_mcp/oauth/auth_code_lifetime';

    public const DEFAULT_SERVER_NAME = 'Magento MCP';
    public const DEFAULT_RATE_LIMITING_RPM = 60;
    public const DEFAULT_OAUTH_AUTH_CODE_LIFETIME = 60;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * @return bool
     */
    public function isAllowWrites(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ALLOW_WRITES);
    }

    /**
     * Server name advertised via `initialize.serverInfo.name`.
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
     * @return string|null
     */
    public function getServerDescription(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SERVER_DESCRIPTION);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * One origin per line; `#` comments and blank lines stripped.
     * Trailing `*` wildcards are handled by {@see \Magebit\Mcp\Model\Validator\OriginValidator}.
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
     * @return int
     */
    public function getAuditRetentionDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_AUDIT_RETENTION_DAYS);
        return is_scalar($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Defaults to off so upgrades retain unlimited throughput until opt-in.
     *
     * @return bool
     */
    public function isRateLimitingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RATE_LIMITING_ENABLED);
    }

    /**
     * Max `tools/call` invocations per (admin-user, tool) per minute.
     * `<= 0` short-circuits the limiter (unlimited).
     *
     * @return int
     */
    public function getRateLimitRequestsPerMinute(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_RATE_LIMITING_RPM);
        if (!is_scalar($value)) {
            return self::DEFAULT_RATE_LIMITING_RPM;
        }
        return max(0, (int) $value);
    }

    /**
     * Lifetime in seconds for OAuth 2.1 authorization codes. One-shot,
     * short-lived; defaults to 60s when unset or non-positive.
     *
     * @return int
     */
    public function getOAuthAuthCodeLifetime(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_AUTH_CODE_LIFETIME);
        $seconds = is_scalar($value) ? (int) $value : 0;
        return $seconds > 0 ? $seconds : self::DEFAULT_OAUTH_AUTH_CODE_LIFETIME;
    }
}
