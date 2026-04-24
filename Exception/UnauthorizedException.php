<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Exception;

use RuntimeException;

/**
 * Bearer verification failure; mapped to HTTP 401 + JSON-RPC UNAUTHORIZED.
 */
class UnauthorizedException extends RuntimeException
{
}
