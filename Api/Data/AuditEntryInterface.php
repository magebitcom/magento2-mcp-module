<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data;

/**
 * One row in magebit_mcp_audit_log.
 *
 * Audit entries are deliberately coarse-grained — arguments are redacted +
 * size-capped, responses are summarized (never full body). See
 * {@see \Magebit\Mcp\Model\PiiRedactor} for the hashing scheme.
 */
interface AuditEntryInterface
{
    public const ID = 'id';
    public const TOKEN_ID = 'token_id';
    public const ADMIN_USER_ID = 'admin_user_id';
    public const REQUEST_ID = 'request_id';
    public const PROTOCOL_VERSION = 'protocol_version';
    public const METHOD = 'method';
    public const TOOL_NAME = 'tool_name';
    public const ARGUMENTS_JSON = 'arguments_json';
    public const RESULT_SUMMARY_JSON = 'result_summary_json';
    public const RESPONSE_STATUS = 'response_status';
    public const ERROR_CODE = 'error_code';
    public const DURATION_MS = 'duration_ms';
    public const IP_ADDRESS = 'ip_address';
    public const USER_AGENT = 'user_agent';
    public const CREATED_AT = 'created_at';

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    /**
     * Primary key. Null for unpersisted rows.
     */
    public function getId(): ?int;

    /**
     * Token that authenticated the request, or null if auth failed before resolution.
     */
    public function getTokenId(): ?int;

    /**
     * Admin user behind the token, or null if no token was resolved.
     */
    public function getAdminUserId(): ?int;

    /**
     * JSON-RPC request id echoed back — may be a number, string, or null.
     */
    public function getRequestId(): ?string;

    /**
     * Value of the Mcp-Protocol-Version header on the request.
     */
    public function getProtocolVersion(): ?string;

    /**
     * JSON-RPC method name (e.g. `initialize`, `tools/call`).
     */
    public function getMethod(): string;

    /**
     * Tool name for `tools/call` rows; null for lifecycle methods.
     */
    public function getToolName(): ?string;

    /**
     * Redacted arguments payload (JSON-encoded, PII-fingerprinted, size-capped).
     */
    public function getArgumentsJson(): ?string;

    /**
     * Tool-computed result summary (JSON-encoded); never the full response body.
     */
    public function getResultSummaryJson(): ?string;

    /**
     * Coarse response status — `ok` or `error`.
     */
    public function getResponseStatus(): string;

    /**
     * Numeric error code as string, or null when the response was successful.
     */
    public function getErrorCode(): ?string;

    /**
     * Wall-clock duration of the request in milliseconds.
     */
    public function getDurationMs(): ?int;

    /**
     * Remote IP address.
     */
    public function getIpAddress(): ?string;

    /**
     * User-Agent header value.
     */
    public function getUserAgent(): ?string;

    /**
     * Creation timestamp set at insert time.
     */
    public function getCreatedAt(): ?string;
}
