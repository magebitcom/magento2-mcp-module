<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;

/**
 * Handles the `notifications/initialized` notification sent after a successful
 * `initialize` handshake. Notifications expect no response — this handler
 * always returns null, which the transport translates into a 202 with no body.
 */
class InitializedNotificationHandler implements HandlerInterface
{
    /**
     * @inheritDoc
     */
    public function method(): string
    {
        return 'notifications/initialized';
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AuthenticatedContext $context): ?Response
    {
        unset($request, $context);
        return null;
    }
}
