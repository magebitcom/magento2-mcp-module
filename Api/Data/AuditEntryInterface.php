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

    public function getId(): ?int;

    public function getTokenId(): ?int;

    public function getAdminUserId(): ?int;

    public function getRequestId(): ?string;

    public function getProtocolVersion(): ?string;

    public function getMethod(): string;

    public function getToolName(): ?string;

    public function getArgumentsJson(): ?string;

    public function getResultSummaryJson(): ?string;

    public function getResponseStatus(): string;

    public function getErrorCode(): ?string;

    public function getDurationMs(): ?int;

    public function getIpAddress(): ?string;

    public function getUserAgent(): ?string;

    public function getCreatedAt(): ?string;
}
