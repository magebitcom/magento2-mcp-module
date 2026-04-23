<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;

/**
 * Contract every JSON-RPC method handler implements.
 *
 * Handlers are registered with the {@see Dispatcher} via DI array injection
 * in etc/di.xml. The dispatcher indexes them by their own `method()` return
 * value — the array key in di.xml is informational only.
 *
 * The authenticated context is passed through so handlers can make decisions
 * based on the acting admin user (ACL, write gate, rate limiting) without
 * consulting session state.
 */
interface HandlerInterface
{
    /**
     * JSON-RPC method name this handler responds to (e.g. "tools/list").
     */
    public function method(): string;

    /**
     * Handle the request.
     *
     * Return null ONLY for notification methods (the client sent no `id` and
     * expects no response). Requests with an id MUST return a Response.
     */
    public function handle(Request $request, AuthenticatedContext $context): ?Response;
}
