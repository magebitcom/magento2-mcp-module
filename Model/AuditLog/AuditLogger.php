<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Throwable;

/**
 * Writes one row to magebit_mcp_audit_log via raw
 * {@see ResourceConnection::getConnection()} INSERT (never a repository / model), so:
 *   - save-event observers don't fire (no feedback loop);
 *   - enclosing transactions don't drag the row down on rollback — audit must
 *     survive the caller's failure;
 *   - storage errors never 500 the request ({@see self::write()} swallows Throwable).
 *
 * {@see PiiRedactor} redacts arguments here as last-line defense; payload is
 * capped at {@see self::MAX_JSON_BYTES} to prevent a log-column DoS.
 */
class AuditLogger
{
    private const TABLE = 'magebit_mcp_audit_log';
    private const MAX_JSON_BYTES = 4096;

    /**
     * @param ResourceConnection $resourceConnection
     * @param DateTime $dateTime
     * @param PiiRedactor $redactor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime,
        private readonly PiiRedactor $redactor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param AuditContext $context
     * @return void
     */
    public function write(AuditContext $context): void
    {
        try {
            $this->doWrite($context);
        } catch (Throwable $e) {
            $this->logger->error('MCP audit log write failed.', [
                'exception' => $e,
                'method' => $context->method,
                'tool' => $context->toolName,
            ]);
        }
    }

    /**
     * @param AuditContext $context
     * @return void
     */
    private function doWrite(AuditContext $context): void
    {
        $connection = $this->resourceConnection->getConnection();

        $row = [
            AuditEntryInterface::TOKEN_ID => $context->tokenId,
            AuditEntryInterface::ADMIN_USER_ID => $context->adminUserId,
            AuditEntryInterface::REQUEST_ID => $this->serializeRequestId($context->requestId),
            AuditEntryInterface::PROTOCOL_VERSION => $context->protocolVersion,
            AuditEntryInterface::METHOD => $context->method,
            AuditEntryInterface::TOOL_NAME => $context->toolName,
            AuditEntryInterface::ARGUMENTS_JSON => $this->encodeArguments($context->arguments),
            AuditEntryInterface::RESULT_SUMMARY_JSON => $this->encodeSummary($context->resultSummary),
            AuditEntryInterface::RESPONSE_STATUS => $context->responseStatus !== ''
                ? $context->responseStatus
                : AuditEntryInterface::STATUS_OK,
            AuditEntryInterface::ERROR_CODE => $context->errorCode,
            AuditEntryInterface::DURATION_MS => $context->durationMs,
            AuditEntryInterface::IP_ADDRESS => $context->ipAddress,
            AuditEntryInterface::USER_AGENT => $this->truncate($context->userAgent, 255),
            AuditEntryInterface::CREATED_AT => $this->dateTime->gmtDate(),
        ];

        $connection->insert($this->resourceConnection->getTableName(self::TABLE), $row);
    }

    /**
     * @param int|string|null $id
     * @return string|null
     */
    private function serializeRequestId(int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }
        return $this->truncate((string) $id, 128);
    }

    /**
     * @param array|null $arguments
     * @phpstan-param array<int|string, mixed>|null $arguments
     * @return string|null
     */
    private function encodeArguments(?array $arguments): ?string
    {
        if ($arguments === null) {
            return null;
        }
        $redacted = $this->redactor->redact($arguments);
        if (!is_array($redacted)) {
            return null;
        }
        return $this->encode($redacted);
    }

    /**
     * @param array|null $summary
     * @phpstan-param array<int|string, mixed>|null $summary
     * @return string|null
     */
    private function encodeSummary(?array $summary): ?string
    {
        if ($summary === null || $summary === []) {
            return null;
        }
        return $this->encode($summary);
    }

    /**
     * Applies a hard byte cap with `__truncated__` sentinel.
     *
     * @param array $data
     * @phpstan-param array<int|string, mixed> $data
     * @return string|null
     */
    private function encode(array $data): ?string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            return substr($encoded, 0, self::MAX_JSON_BYTES - 20) . '..."__truncated__"';
        }
        return $encoded;
    }

    /**
     * @param string|null $value
     * @param int $maxBytes
     * @return string|null
     */
    private function truncate(?string $value, int $maxBytes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        return substr($value, 0, $maxBytes);
    }
}
