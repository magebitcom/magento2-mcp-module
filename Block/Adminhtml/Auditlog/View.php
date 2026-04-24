<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Auditlog;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Controller\Adminhtml\Auditlog\View as ViewController;
use Magebit\Mcp\Model\AuditEntry;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\User\Model\User;

/**
 * View model for the MCP audit log entry detail page. Returns raw strings —
 * HTML escaping happens in the template via `$escaper`.
 */
class View extends Template
{
    /**
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly TokenRepository $tokenRepository,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return AuditEntry|null
     */
    public function getEntry(): ?AuditEntry
    {
        $entry = $this->registry->registry(ViewController::REGISTRY_KEY);
        return $entry instanceof AuditEntry ? $entry : null;
    }

    /**
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('magebit_mcp/auditlog/index');
    }

    /**
     * @return User|null
     */
    public function getAdminUser(): ?User
    {
        $entry = $this->getEntry();
        if ($entry === null) {
            return null;
        }
        $id = $entry->getAdminUserId();
        if ($id === null || $id <= 0) {
            return null;
        }
        try {
            return $this->adminUserLookup->getById($id);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @return string|null
     */
    public function getAdminUserEditUrl(): ?string
    {
        $user = $this->getAdminUser();
        if ($user === null) {
            return null;
        }
        return $this->getUrl('adminhtml/user/edit', ['user_id' => $user->getId()]);
    }

    /**
     * @return string|null
     */
    public function getTokenName(): ?string
    {
        $entry = $this->getEntry();
        if ($entry === null) {
            return null;
        }
        $id = $entry->getTokenId();
        if ($id === null || $id <= 0) {
            return null;
        }
        $tokens = $this->tokenRepository->listByIds([$id]);
        $token = $tokens[$id] ?? null;
        if ($token === null) {
            return null;
        }
        $name = trim($token->getName());
        return $name === '' ? null : $name;
    }

    /**
     * @return string|null
     */
    public function getErrorLabel(): ?string
    {
        $entry = $this->getEntry();
        if ($entry === null) {
            return null;
        }
        $code = $entry->getErrorCode();
        if ($code === null || $code === '') {
            return null;
        }
        if (!is_numeric($code)) {
            return $code;
        }
        return sprintf('%s (%d)', ErrorCode::labelFor((int) $code), (int) $code);
    }

    /**
     * @return string|null
     */
    public function getCreatedAtFormatted(): ?string
    {
        $entry = $this->getEntry();
        if ($entry === null) {
            return null;
        }
        $raw = $entry->getCreatedAt();
        if ($raw === null || $raw === '') {
            return null;
        }
        $dateTime = $this->timezone->date($raw);
        return $this->timezone->formatDate($dateTime, \IntlDateFormatter::MEDIUM, true);
    }

    /**
     * Falls back to the raw string when decode fails so malformed history rows still render.
     *
     * @return string|null
     */
    public function getPrettyArguments(): ?string
    {
        $entry = $this->getEntry();
        return $entry === null ? null : $this->prettyPrint($entry->getArgumentsJson());
    }

    /**
     * @return string|null
     */
    public function getPrettyResultSummary(): ?string
    {
        $entry = $this->getEntry();
        return $entry === null ? null : $this->prettyPrint($entry->getResultSummaryJson());
    }

    /**
     * @return bool
     */
    public function isOk(): bool
    {
        $entry = $this->getEntry();
        if ($entry === null) {
            return false;
        }
        return $entry->getResponseStatus() === AuditEntryInterface::STATUS_OK;
    }

    /**
     * @param string|null $json
     * @return string|null
     */
    private function prettyPrint(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        $pretty = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return $pretty === false ? $json : $pretty;
    }
}
