<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one row to magebit_mcp_audit_log.
 *
 * Goes via a raw {@see ResourceConnection::getConnection()} INSERT — never a
 * repository, never an ObjectManager-created model — so:
 *   - we don't fire save-event observers (would create a feedback loop);
 *   - we bypass any enclosing transaction (if the caller's transaction rolls
 *     back, the audit row still survives — that's the whole point of an
 *     audit log);
 *   - logging failures never 500 the request (`write()` swallows Throwable
 *     and logs a warning).
 *
 * Argument redaction is applied here (via {@see PiiRedactor}) as a last line
 * of defense — callers may forget. Payload is capped at {@see self::MAX_JSON_BYTES}
 * to keep the log column from becoming a DoS vector.
 */
class AuditLogger
{
    private const TABLE = 'magebit_mcp_audit_log';
    private const MAX_JSON_BYTES = 4096;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime,
        private readonly PiiRedactor $redactor,
        private readonly LoggerInterface $logger
    ) {
    }

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

    private function serializeRequestId(int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }
        return $this->truncate((string) $id, 128);
    }

    /**
     * @param array<int|string, mixed>|null $arguments
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
     * @param array<int|string, mixed>|null $summary
     */
    private function encodeSummary(?array $summary): ?string
    {
        if ($summary === null || $summary === []) {
            return null;
        }
        return $this->encode($summary);
    }

    /**
     * @param array<int|string, mixed> $data
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
