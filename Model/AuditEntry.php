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
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

/**
 * Read-only row of the MCP audit log; writes go through {@see AuditLog\AuditLogger}.
 */
class AuditEntry extends AbstractModel implements AuditEntryInterface
{
    use ReturnsStrictTypedData;

    protected function _construct(): void
    {
        $this->_init(AuditEntryResource::class);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->getDataIntOrNull(self::ID, cast: true);
    }

    /**
     * @return int|null
     */
    public function getTokenId(): ?int
    {
        return $this->getDataIntOrNull(self::TOKEN_ID, cast: true);
    }

    /**
     * @return int|null
     */
    public function getAdminUserId(): ?int
    {
        return $this->getDataIntOrNull(self::ADMIN_USER_ID, cast: true);
    }

    /**
     * @return string|null
     */
    public function getRequestId(): ?string
    {
        return $this->getDataStringOrNull(self::REQUEST_ID);
    }

    /**
     * @return string|null
     */
    public function getProtocolVersion(): ?string
    {
        return $this->getDataStringOrNull(self::PROTOCOL_VERSION);
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->getDataString(self::METHOD);
    }

    /**
     * @return string|null
     */
    public function getToolName(): ?string
    {
        return $this->getDataStringOrNull(self::TOOL_NAME);
    }

    /**
     * @return string|null
     */
    public function getPromptName(): ?string
    {
        return $this->getDataStringOrNull(self::PROMPT_NAME);
    }

    /**
     * @return string|null
     */
    public function getArgumentsJson(): ?string
    {
        return $this->getDataStringOrNull(self::ARGUMENTS_JSON);
    }

    /**
     * @return string|null
     */
    public function getResultSummaryJson(): ?string
    {
        return $this->getDataStringOrNull(self::RESULT_SUMMARY_JSON);
    }

    /**
     * @return string
     */
    public function getResponseStatus(): string
    {
        return $this->getDataString(self::RESPONSE_STATUS);
    }

    /**
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->getDataStringOrNull(self::ERROR_CODE);
    }

    /**
     * @return int|null
     */
    public function getDurationMs(): ?int
    {
        return $this->getDataIntOrNull(self::DURATION_MS, cast: true);
    }

    /**
     * @return string|null
     */
    public function getIpAddress(): ?string
    {
        return $this->getDataStringOrNull(self::IP_ADDRESS);
    }

    /**
     * @return string|null
     */
    public function getUserAgent(): ?string
    {
        return $this->getDataStringOrNull(self::USER_AGENT);
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getDataStringOrNull(self::CREATED_AT);
    }
}
