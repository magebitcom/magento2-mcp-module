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
    public const XML_PATH_ALLOW_WRITES = 'magebit_mcp/general/allow_writes';
    public const XML_PATH_SERVER_TITLE = 'magebit_mcp/server_info/title';
    public const XML_PATH_SERVER_DESCRIPTION = 'magebit_mcp/server_info/description';
    public const XML_PATH_SERVER_WEBSITE_URL = 'magebit_mcp/server_info/website_url';
    public const XML_PATH_SERVER_ICON_URL = 'magebit_mcp/server_info/icon_url';
    public const XML_PATH_SERVER_ICON_MIME_TYPE = 'magebit_mcp/server_info/icon_mime_type';
    public const XML_PATH_SERVER_ICON_SIZES = 'magebit_mcp/server_info/icon_sizes';
    public const XML_PATH_SERVER_INSTRUCTIONS = 'magebit_mcp/server_info/instructions';
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
     * @return string
     */
    public function getServerName(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SERVER_NAME);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : self::DEFAULT_SERVER_NAME;
    }

    /**
     * Display title advertised via `initialize.serverInfo.title`. Falls back
     * to the storefront's `Store Information → Store Name` so a fresh install
     * carries the operator's brand without manual setup.
     *
     * @return ?string
     */
    public function getServerTitle(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_SERVER_TITLE)
            ?? $this->readNonEmptyString('general/store_information/name');
    }

    /**
     * Short description advertised via `initialize.serverInfo.description`.
     * @return ?string
     */
    public function getServerDescription(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_SERVER_DESCRIPTION);
    }

    /**
     * Website URL advertised via `initialize.serverInfo.websiteUrl`. Falls
     * back to the store's secure base URL so the field points somewhere
     * useful out of the box; admins can override with a marketing page.
     *
     * @return ?string
     */
    public function getServerWebsiteUrl(): ?string
    {
        $value = $this->readNonEmptyString(self::XML_PATH_SERVER_WEBSITE_URL);
        if ($value !== null) {
            return $value;
        }
        $baseUrl = $this->readNonEmptyString('web/secure/base_url')
            ?? $this->readNonEmptyString('web/unsecure/base_url');
        return $baseUrl !== null ? rtrim($baseUrl, '/') : null;
    }

    /**
     * Free-text guidance advertised via top-level `initialize.instructions`.
     * @return ?string
     */
    public function getServerInstructions(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_SERVER_INSTRUCTIONS);
    }

    /**
     * Single icon entry for `initialize.serverInfo.icons[]`. Returns null
     * unless both URL and MIME type are configured — a URL without a
     * declared MIME type is dropped rather than guessed.
     *
     * @return ?array{src: string, mimeType: string, sizes: list<string>}
     */
    public function getServerIcon(): ?array
    {
        $src = $this->readNonEmptyString(self::XML_PATH_SERVER_ICON_URL);
        $mimeType = $this->readNonEmptyString(self::XML_PATH_SERVER_ICON_MIME_TYPE);
        if ($src === null || $mimeType === null) {
            return null;
        }

        $rawSizes = $this->readNonEmptyString(self::XML_PATH_SERVER_ICON_SIZES) ?? 'any';
        $sizes = [];
        foreach (explode(',', $rawSizes) as $size) {
            $size = trim($size);
            if ($size !== '') {
                $sizes[] = $size;
            }
        }
        if ($sizes === []) {
            $sizes = ['any'];
        }

        return ['src' => $src, 'mimeType' => $mimeType, 'sizes' => $sizes];
    }

    /**
     * @param string $path
     * @return ?string
     */
    private function readNonEmptyString(string $path): ?string
    {
        $value = $this->scopeConfig->getValue($path);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * One origin per line; `#` comments and blank lines stripped. Trailing `*` wildcards
     * are handled by {@see \Magebit\Mcp\Model\Validator\OriginValidator}.
     *
     * @return string[]
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
     * @return bool
     */
    public function isRateLimitingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RATE_LIMITING_ENABLED);
    }

    /**
     * Max `tools/call` invocations per (admin-user, tool) per minute. `<= 0` = unlimited.
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
     * @return int
     */
    public function getOAuthAuthCodeLifetime(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_AUTH_CODE_LIFETIME);
        $seconds = is_scalar($value) ? (int) $value : 0;
        return $seconds > 0 ? $seconds : self::DEFAULT_OAUTH_AUTH_CODE_LIFETIME;
    }

    /**
     * @return int
     */
    public function getOAuthAccessTokenLifetime(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_ACCESS_TOKEN_LIFETIME);
        $seconds = is_scalar($value) ? (int) $value : 0;
        return $seconds > 0 ? $seconds : self::DEFAULT_OAUTH_ACCESS_TOKEN_LIFETIME;
    }

    /**
     * @return int
     */
    public function getOAuthRefreshTokenLifetimeDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS);
        $days = is_scalar($value) ? (int) $value : 0;
        return $days > 0 ? $days : self::DEFAULT_OAUTH_REFRESH_TOKEN_LIFETIME_DAYS;
    }
}
