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
 * implementation-defined -32000..-32099 range. Adding a case forces a matching
 * {@see self::label()} branch in the same commit.
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
    case PROMPT_NOT_FOUND = -32016;

    /**
     * Human-readable label rendered in the audit-log admin grid.
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
            self::PROMPT_NOT_FOUND => 'Prompt not found',
        };
    }

    /**
     * Resolve a raw wire-format integer to a label, falling back when the code
     * has since been removed (old audit rows may still reference it).
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function labelFor(int $code): string
    {
        return self::tryFrom($code)?->label() ?? 'Unknown error';
    }
}
