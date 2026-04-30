<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Adminhtml;

use Magento\Backend\Model\Session;

/**
 * Request-scoped carrier for an admin form's bounced-payload session data.
 *
 * Form blocks split across multiple tabs both need the same payload on a validation
 * bounce; without one read-and-clear point either the first tab's read blanks the
 * other, or the entry leaks into unrelated future pageviews. This service reads the
 * session entry once and clears it on first access, then serves the cached copy to
 * every subsequent caller in the same request.
 *
 * Per-form instances are wired as virtualTypes in di.xml — each form gets its own
 * session key so two forms can bounce-restore independently.
 */
class FormDataPersistence
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    /** @var bool */
    private bool $loaded = false;

    /**
     * @param Session $backendSession
     * @param string $sessionKey
     */
    public function __construct(
        private readonly Session $backendSession,
        private readonly string $sessionKey
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function set(array $payload): void
    {
        $this->backendSession->setData($this->sessionKey, $payload);
        $this->cached = $payload;
        $this->loaded = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        if ($this->loaded) {
            return $this->cached;
        }
        $this->loaded = true;
        $raw = $this->backendSession->getData($this->sessionKey, true);
        $this->cached = is_array($raw) ? $raw : null;
        return $this->cached;
    }
}
