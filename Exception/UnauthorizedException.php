<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Magebit\Mcp\Model\Auth\TokenAuthenticator} when a bearer
 * fails verification. The controller translates this into an HTTP 401 with a
 * `WWW-Authenticate: Bearer realm="Magento MCP"` header and a JSON-RPC
 * {@see \Magebit\Mcp\Model\JsonRpc\ErrorCode::UNAUTHORIZED} error body.
 */
class UnauthorizedException extends RuntimeException
{
}
