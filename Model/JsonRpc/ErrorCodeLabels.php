<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * Human-readable labels for the JSON-RPC / MCP error codes written to the
 * audit log. Kept alongside {@see ErrorCode} so new codes can't be added
 * without a label falling out of sync — the admin grid falls back to
 * "Unknown" rather than breaking on unseen codes.
 */
final class ErrorCodeLabels
{
    /** @var array<int, string> */
    private const LABELS = [
        ErrorCode::PARSE_ERROR => 'Parse error',
        ErrorCode::INVALID_REQUEST => 'Invalid request',
        ErrorCode::METHOD_NOT_FOUND => 'Method not found',
        ErrorCode::INVALID_PARAMS => 'Invalid params',
        ErrorCode::INTERNAL_ERROR => 'Internal error',
        ErrorCode::UNAUTHORIZED => 'Unauthorized',
        ErrorCode::INVALID_ORIGIN => 'Invalid origin',
        ErrorCode::UNSUPPORTED_PROTOCOL_VERSION => 'Unsupported protocol version',
        ErrorCode::FORBIDDEN => 'Forbidden',
        ErrorCode::TOOL_NOT_FOUND => 'Tool not found',
        ErrorCode::TOOL_EXECUTION_FAILED => 'Tool execution failed',
        ErrorCode::WRITE_NOT_ALLOWED => 'Write not allowed',
        ErrorCode::RATE_LIMITED => 'Rate limited',
        ErrorCode::SCHEMA_VALIDATION_FAILED => 'Schema validation failed',
    ];

    private function __construct()
    {
    }

    public static function labelFor(int $code): string
    {
        return self::LABELS[$code] ?? 'Unknown error';
    }
}
