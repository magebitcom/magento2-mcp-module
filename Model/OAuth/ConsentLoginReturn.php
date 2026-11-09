<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;

/**
 * Decides where an admin goes after signing in on the OAuth consent URL.
 *
 * The admin login form posts back to the URL it was shown on, here the consent
 * URL with its handoff nonce. With "Add Secret Key to URLs" on (the default),
 * Magento sends every fresh login to the startup page instead, so the OAuth
 * flow would stall on the dashboard. A successful login on this route returns
 * to the consent screen for the same handoff instead.
 */
class ConsentLoginReturn
{
    public const ROUTE = 'magebit_mcp/oauth/authorize';

    /**
     * Handoff nonces are hex from {@see \Magebit\Mcp\Model\Auth\TokenGenerator}.
     */
    public const NONCE_PATTERN = '/^[a-f0-9]{16,128}$/i';

    /**
     * @param BackendUrl $backendUrl
     */
    public function __construct(
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * @param bool $loginSubmitted The request carried the admin login form.
     * @param bool $wasLoggedIn An admin session existed before the request was dispatched.
     * @param bool $isLoggedIn An admin session exists after dispatch.
     * @param mixed $nonce The `h` request parameter.
     * @return string|null The consent URL to redirect to, or null to keep Magento's own response.
     */
    public function resolve(bool $loginSubmitted, bool $wasLoggedIn, bool $isLoggedIn, mixed $nonce): ?string
    {
        if (!$loginSubmitted || $wasLoggedIn || !$isLoggedIn) {
            return null;
        }
        if (!is_string($nonce) || preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return null;
        }

        return $this->backendUrl->getUrl(self::ROUTE, ['h' => $nonce, '_secure' => true]);
    }
}
