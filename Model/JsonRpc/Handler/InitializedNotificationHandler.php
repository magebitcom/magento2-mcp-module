<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;

/**
 * Handles the `notifications/initialized` notification sent by MCP clients
 * immediately after a successful `initialize` handshake.
 *
 * Notifications have no `id` and expect no response — this handler always
 * returns null. The HTTP transport layer translates null into a 202 with no
 * body per spec.
 */
class InitializedNotificationHandler implements HandlerInterface
{
    public function method(): string
    {
        return 'notifications/initialized';
    }

    public function handle(Request $request): ?Response
    {
        unset($request);
        return null;
    }
}
