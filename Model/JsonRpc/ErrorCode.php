<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * JSON-RPC 2.0 standard error codes plus MCP-specific codes in the
 * implementation-defined -32000..-32099 range.
 *
 * Backed int enum so the wire format (JSON-RPC requires integer codes) is the
 * single source of truth. Human-readable labels live on the cases themselves
 * via {@see self::label()} — adding a new case therefore forces the author to
 * ship a label in the same commit.
 */
enum ErrorCode: int
{
    case PARSE_ERROR = -32700;
    case INVALID_REQUEST = -32600;
    case METHOD_NOT_FOUND = -32601;
    case INVALID_PARAMS = -32602;
    case INTERNAL_ERROR = -32603;

    case UNAUTHORIZED = -32001;
    case INVALID_ORIGIN = -32002;
    case UNSUPPORTED_PROTOCOL_VERSION = -32003;
    case FORBIDDEN = -32004;
    case TOOL_NOT_FOUND = -32010;
    case TOOL_EXECUTION_FAILED = -32011;
    case WRITE_NOT_ALLOWED = -32012;
    case RATE_LIMITED = -32013;
    case SCHEMA_VALIDATION_FAILED = -32014;
    case SERVER_DISABLED = -32015;

    /**
     * Human-readable label rendered in the audit-log admin grid.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::PARSE_ERROR => 'Parse error',
            self::INVALID_REQUEST => 'Invalid request',
            self::METHOD_NOT_FOUND => 'Method not found',
            self::INVALID_PARAMS => 'Invalid params',
            self::INTERNAL_ERROR => 'Internal error',
            self::UNAUTHORIZED => 'Unauthorized',
            self::INVALID_ORIGIN => 'Invalid origin',
            self::UNSUPPORTED_PROTOCOL_VERSION => 'Unsupported protocol version',
            self::FORBIDDEN => 'Forbidden',
            self::TOOL_NOT_FOUND => 'Tool not found',
            self::TOOL_EXECUTION_FAILED => 'Tool execution failed',
            self::WRITE_NOT_ALLOWED => 'Write not allowed',
            self::RATE_LIMITED => 'Rate limited',
            self::SCHEMA_VALIDATION_FAILED => 'Schema validation failed',
            self::SERVER_DISABLED => 'Server disabled',
        };
    }

    /**
     * Resolve a raw wire-format integer to an enum case, with a fallback label.
     *
     * Used by the admin audit grid where old rows might reference codes that
     * have since been removed from the codebase.
     *
     * @param int $code
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function labelFor(int $code): string
    {
        return self::tryFrom($code)?->label() ?? 'Unknown error';
    }
}
