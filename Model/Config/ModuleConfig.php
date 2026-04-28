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
    public const XML_PATH_PUBLIC_BASE_URL = 'magebit_mcp/general/public_base_url';
    public const XML_PATH_ALLOWED_ORIGINS = 'magebit_mcp/security/allowed_origins';
    public const XML_PATH_AUDIT_RETENTION_DAYS = 'magebit_mcp/audit/retention_days';
    public const XML_PATH_RATE_LIMITING_ENABLED = 'magebit_mcp/rate_limiting/enabled';
    public const XML_PATH_RATE_LIMITING_RPM = 'magebit_mcp/rate_limiting/requests_per_minute';
    public const XML_PATH_OAUTH_AUTH_CODE_LIFETIME = 'magebit_mcp/oauth/auth_code_lifetime';
    public const XML_PATH_OAUTH_ACCESS_TOKEN_LIFETIME = 'magebit_mcp/oauth/access_token_lifetime';
    public const XML_PATH_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS = 'magebit_mcp/oauth/refresh_token_lifetime_days';

    public const DEFAULT_SERVER_NAME = 'Magento MCP';
    public const DEFAULT_RATE_LIMITING_RPM = 60;
    public const DEFAULT_OAUTH_AUTH_CODE_LIFETIME = 60;
    public const DEFAULT_OAUTH_ACCESS_TOKEN_LIFETIME = 3600;
    public const DEFAULT_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS = 30;

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
     * Override for the URL advertised in OAuth discovery documents and the
     * `WWW-Authenticate` challenge. Intentionally not exposed in system.xml —
     * operators set it via `app/etc/env.php` (see README) when running behind
     * a tunnel/proxy that preserves the upstream `Host` (e.g. ngrok keeps
     * `Host: <internal>` and surfaces the public name only in
     * `X-Forwarded-Host`). Returns null when unset, in which case callers
     * fall back to the storefront base URL.
     *
     * @return string|null
     */
    public function getPublicBaseUrl(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PUBLIC_BASE_URL);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? rtrim($value, '/') : null;
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

    /**
     * Lifetime in seconds for OAuth 2.1 access tokens. Defaults to 3600s
     * (one hour) when unset or non-positive.
     *
     * @return int
     */
    public function getOAuthAccessTokenLifetime(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_ACCESS_TOKEN_LIFETIME);
        $seconds = is_scalar($value) ? (int) $value : 0;
        return $seconds > 0 ? $seconds : self::DEFAULT_OAUTH_ACCESS_TOKEN_LIFETIME;
    }

    /**
     * Lifetime in days for OAuth 2.1 refresh tokens. Defaults to 30 days
     * when unset or non-positive.
     *
     * @return int
     */
    public function getOAuthRefreshTokenLifetimeDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS);
        $days = is_scalar($value) ? (int) $value : 0;
        return $days > 0 ? $days : self::DEFAULT_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS;
    }
}
