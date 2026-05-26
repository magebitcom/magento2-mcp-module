<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Result of {@see AdminAuthorizationGate::decide()}.
 */
enum AdminAuthorizationDecision: string
{
    case ALLOW = 'allow';
    case DENIED_NO_ADMIN = 'denied_no_admin';
    case DENIED_NOT_WHITELISTED = 'denied_not_whitelisted';
    case DENIED_SHARED_MISMATCH = 'denied_shared_mismatch';
    case DENIED_CLIENT_DISABLED = 'denied_client_disabled';
    case MISCONFIGURED_NO_SERVICE_ADMIN = 'misconfigured_no_service_admin';

    /**
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this === self::ALLOW;
    }

    /**
     * @return string
     */
    public function oauthError(): string
    {
        return match ($this) {
            self::ALLOW => '',
            self::DENIED_NO_ADMIN,
            self::DENIED_NOT_WHITELISTED,
            self::DENIED_SHARED_MISMATCH => 'access_denied',
            self::DENIED_CLIENT_DISABLED,
            self::MISCONFIGURED_NO_SERVICE_ADMIN => 'server_error',
        };
    }

    /**
     * Generic description safe to leak via `error_description` to a redirect URI.
     * Operator-facing detail goes to the server log, not the client.
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::ALLOW => '',
            self::DENIED_NO_ADMIN => 'Admin session lost during approval.',
            self::DENIED_NOT_WHITELISTED,
            self::DENIED_SHARED_MISMATCH => 'Admin not authorized for this client.',
            self::DENIED_CLIENT_DISABLED,
            self::MISCONFIGURED_NO_SERVICE_ADMIN => 'OAuth client temporarily unavailable.',
        };
    }
}
