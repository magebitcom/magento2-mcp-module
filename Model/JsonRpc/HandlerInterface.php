<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;

/**
 * Contract every JSON-RPC method handler implements. The {@see Dispatcher}
 * indexes handlers by their own `method()` return value — the di.xml array
 * key is informational only.
 */
interface HandlerInterface
{
    /**
     * JSON-RPC method name this handler responds to (e.g. "tools/list").
     */
    public function method(): string;

    /**
     * Return null ONLY for notification methods (no client `id`, no response).
     * Requests with an id MUST return a Response.
     */
    public function handle(Request $request, AuthenticatedContext $context): ?Response;
}
