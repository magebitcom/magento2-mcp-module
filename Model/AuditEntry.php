<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Model\ResourceModel\AuditEntry as AuditEntryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Read-only row of the MCP audit log; writes go through {@see AuditLogger}.
 */
class AuditEntry extends AbstractModel implements AuditEntryInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(AuditEntryResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getId(): ?int
    {
        $v = $this->getData(self::ID);
        return is_scalar($v) ? (int) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function getTokenId(): ?int
    {
        $v = $this->getData(self::TOKEN_ID);
        return is_scalar($v) ? (int) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function getAdminUserId(): ?int
    {
        $v = $this->getData(self::ADMIN_USER_ID);
        return is_scalar($v) ? (int) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function getRequestId(): ?string
    {
        return $this->stringOrNull(self::REQUEST_ID);
    }

    /**
     * @inheritDoc
     */
    public function getProtocolVersion(): ?string
    {
        return $this->stringOrNull(self::PROTOCOL_VERSION);
    }

    /**
     * @inheritDoc
     */
    public function getMethod(): string
    {
        $v = $this->getData(self::METHOD);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function getToolName(): ?string
    {
        return $this->stringOrNull(self::TOOL_NAME);
    }

    /**
     * @inheritDoc
     */
    public function getArgumentsJson(): ?string
    {
        return $this->stringOrNull(self::ARGUMENTS_JSON);
    }

    /**
     * @inheritDoc
     */
    public function getResultSummaryJson(): ?string
    {
        return $this->stringOrNull(self::RESULT_SUMMARY_JSON);
    }

    /**
     * @inheritDoc
     */
    public function getResponseStatus(): string
    {
        $v = $this->getData(self::RESPONSE_STATUS);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function getErrorCode(): ?string
    {
        return $this->stringOrNull(self::ERROR_CODE);
    }

    /**
     * @inheritDoc
     */
    public function getDurationMs(): ?int
    {
        $v = $this->getData(self::DURATION_MS);
        return is_scalar($v) ? (int) $v : null;
    }

    /**
     * @inheritDoc
     */
    public function getIpAddress(): ?string
    {
        return $this->stringOrNull(self::IP_ADDRESS);
    }

    /**
     * @inheritDoc
     */
    public function getUserAgent(): ?string
    {
        return $this->stringOrNull(self::USER_AGENT);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->stringOrNull(self::CREATED_AT);
    }

    private function stringOrNull(string $field): ?string
    {
        $v = $this->getData($field);
        if (is_scalar($v)) {
            $s = (string) $v;
            return $s === '' ? null : $s;
        }
        return null;
    }
}
