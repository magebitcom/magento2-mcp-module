<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magento\Backend\Model\Session;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Remembers, in the admin session, a consent screen that another admin step
 * (two-factor authentication) intercepted, so the admin can be sent back to
 * it once that step is done. The marker lives no longer than the handoff, so
 * a step that never returns to the startup page cannot leave it behind.
 */
class PendingConsent
{
    private const SESSION_KEY = 'magebit_mcp_pending_consent';
    public const TTL_SECONDS = AuthorizeHandoffStorage::DEFAULT_TTL_SECONDS;

    /**
     * @param Session $session
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Session $session,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param mixed $nonce The `h` request parameter; ignored unless it is a well-formed handoff nonce.
     * @return void
     */
    public function remember(mixed $nonce): void
    {
        if (self::isWellFormed($nonce)) {
            $this->session->setData(self::SESSION_KEY, ['nonce' => $nonce, 'at' => $this->now()]);
        }
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        $this->session->getData(self::SESSION_KEY, true);
    }

    /**
     * @return string|null Admin route path of the pending consent screen, or null when none is pending.
     */
    public function getRoutePath(): ?string
    {
        $pending = $this->session->getData(self::SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $nonce = $pending['nonce'] ?? null;
        $at = $pending['at'] ?? null;
        if (!self::isWellFormed($nonce) || !is_int($at) || $this->now() - $at > self::TTL_SECONDS) {
            $this->forget();
            return null;
        }

        return ConsentLoginReturn::ROUTE . '/h/' . $nonce;
    }

    /**
     * @return int
     */
    private function now(): int
    {
        return (int) $this->dateTime->gmtTimestamp();
    }

    /**
     * @param mixed $nonce
     * @return bool
     * @phpstan-assert-if-true string $nonce
     */
    private static function isWellFormed(mixed $nonce): bool
    {
        return is_string($nonce) && preg_match(ConsentLoginReturn::NONCE_PATTERN, $nonce) === 1;
    }
}
