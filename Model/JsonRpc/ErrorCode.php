<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * JSON-RPC 2.0 standard error codes plus MCP-specific codes in the
 * implementation-defined -32000..-32099 range.
 */
final class ErrorCode
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public const UNAUTHORIZED = -32001;
    public const INVALID_ORIGIN = -32002;
    public const UNSUPPORTED_PROTOCOL_VERSION = -32003;
    public const FORBIDDEN = -32004;
    public const TOOL_NOT_FOUND = -32010;
    public const TOOL_EXECUTION_FAILED = -32011;
    public const WRITE_NOT_ALLOWED = -32012;
    public const RATE_LIMITED = -32013;
    public const SCHEMA_VALIDATION_FAILED = -32014;

    private function __construct()
    {
    }
}
