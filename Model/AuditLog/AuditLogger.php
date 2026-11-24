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
 * Writes one row to `magebit_mcp_audit_log` via a raw INSERT — no repository, no model
 * events, and storage errors are swallowed so a failed write never 500s the request.
 * {@see PiiRedactor} runs as a last-line defense and each JSON payload is capped to
 * prevent log-column DoS: arguments at {@see self::ARGUMENTS_BUDGET_BYTES}, the result summary at
 * {@see self::SUMMARY_BUDGET_BYTES}. Both columns are JSON columns (MariaDB checks `json_valid`, MySQL types them),
 * so whatever is stored must be valid JSON or the whole row is refused. A summary with per-item detail (`items`)
 * keeps whole leading entries and `items_truncated` counts the rest; any other payload over its budget is replaced
 * by the envelope `{"__truncated__": true, "original_bytes": N, "head": "<start of the encoded JSON>"}`.
 */
class AuditLogger
{
    public const ARGUMENTS_BUDGET_BYTES = 4096;
    public const SUMMARY_BUDGET_BYTES = 32768;

    private const TABLE = 'magebit_mcp_audit_log';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
            AuditEntryInterface::PROMPT_NAME => $context->promptName,
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
     * @return ?string
     */
    private function serializeRequestId(int|string|null $id): ?string
    {
        return $id === null ? null : $this->truncate((string) $id, 128);
    }

    /**
     * @param array<int|string, mixed>|null $arguments
     * @return string|null
     */
    private function encodeArguments(?array $arguments): ?string
    {
        if ($arguments === null) {
            return null;
        }
        $redacted = $this->redactor->redact($arguments);
        return is_array($redacted) ? $this->encode($redacted, self::ARGUMENTS_BUDGET_BYTES) : null;
    }

    /**
     * @param array<int|string, mixed>|null $summary
     * @return string|null
     */
    private function encodeSummary(?array $summary): ?string
    {
        if ($summary === null || $summary === []) {
            return null;
        }
        // Redacted first, so the budget applies to what is actually stored.
        $redacted = $this->redactor->redact($summary);
        if (!is_array($redacted)) {
            return null;
        }
        if (isset($redacted['items']) && is_array($redacted['items']) && array_is_list($redacted['items'])) {
            return $this->encodeWithItems($redacted, $redacted['items']);
        }

        return $this->encode($redacted, self::SUMMARY_BUDGET_BYTES);
    }

    /**
     * Keeps the largest number of leading `items` entries that fits the summary budget and adds what was left out
     * to `items_truncated` (on top of any count the collector already set).
     *
     * @param array<int|string, mixed> $summary
     * @param list<mixed> $items
     * @return string|null
     */
    private function encodeWithItems(array $summary, array $items): ?string
    {
        $whole = json_encode($summary, self::JSON_FLAGS);
        if ($whole === false) {
            return null;
        }
        if (strlen($whole) <= self::SUMMARY_BUDGET_BYTES) {
            return $whole;
        }

        $already = isset($summary['items_truncated']) && is_int($summary['items_truncated'])
            ? $summary['items_truncated']
            : 0;
        // The size grows with every kept entry, so the largest count that fits is found by bisection.
        $low = 0;
        $high = count($items) - 1;
        $best = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = $this->withLeadingItems($summary, $items, $mid, $already);
            if ($candidate !== null && strlen($candidate) <= self::SUMMARY_BUDGET_BYTES) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($best !== null) {
            return $best;
        }

        // Even without entries the rest of the summary is over budget: drop `items`, keep the count, and let the
        // envelope for a summary without items handle what remains.
        unset($summary['items']);
        $summary['items_truncated'] = $already + count($items);
        return $this->encode($summary, self::SUMMARY_BUDGET_BYTES);
    }

    /**
     * @param array<int|string, mixed> $summary
     * @param list<mixed> $items
     * @param int $keep
     * @param int $already
     * @return string|null
     */
    private function withLeadingItems(array $summary, array $items, int $keep, int $already): ?string
    {
        $summary['items'] = array_slice($items, 0, $keep);
        $summary['items_truncated'] = $already + count($items) - $keep;
        $encoded = json_encode($summary, self::JSON_FLAGS);
        return $encoded === false ? null : $encoded;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param int $budget
     * @return string|null
     */
    private function encode(array $data, int $budget): ?string
    {
        $encoded = json_encode($data, self::JSON_FLAGS);
        if ($encoded === false) {
            return null;
        }
        if (strlen($encoded) > $budget) {
            return $this->truncationEnvelope($encoded, $budget);
        }
        return $encoded;
    }

    /**
     * A valid-JSON stand-in for a payload over its budget, holding the start of the encoded payload as a string.
     *
     * The head starts at all the room left and shrinks until the envelope fits. Every byte removed from the head
     * removes one or two bytes of output (`"` and `\` are the only characters left to escape in already-encoded
     * JSON; multibyte characters stay as they are), so each round removes half the overshoot, rounded up: never
     * more than needed, and the overshoot at least halves each round. A head of half the room always fits.
     * `mb_strcut` never splits a UTF-8 character.
     *
     * @param string $encoded
     * @param int $budget
     * @return string|null
     */
    private function truncationEnvelope(string $encoded, int $budget): ?string
    {
        $envelope = ['__truncated__' => true, 'original_bytes' => strlen($encoded), 'head' => ''];
        $empty = json_encode($envelope, self::JSON_FLAGS);
        if ($empty === false) {
            return null;
        }
        $headBytes = max(0, $budget - strlen($empty));
        while (true) {
            $envelope['head'] = mb_strcut($encoded, 0, $headBytes, 'UTF-8');
            $result = json_encode($envelope, self::JSON_FLAGS);
            if ($result === false) {
                return null;
            }
            if (strlen($result) <= $budget || $headBytes === 0) {
                return $result;
            }
            $overshoot = strlen($result) - $budget;
            $headBytes = max(0, min($headBytes, strlen($envelope['head'])) - intdiv($overshoot + 1, 2));
        }
    }

    /**
     * @param ?string $value
     * @param int $maxBytes
     * @return ?string
     */
    private function truncate(?string $value, int $maxBytes): ?string
    {
        if ($value === null) {
            return null;
        }
        return strlen($value) <= $maxBytes ? $value : substr($value, 0, $maxBytes);
    }
}
