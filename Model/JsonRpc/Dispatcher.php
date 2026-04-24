<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use InvalidArgumentException;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Throwable;

/**
 * Routes JSON-RPC requests to their method handlers.
 *
 * Handlers are injected as a flat array from etc/di.xml and indexed at
 * construction by their own {@see HandlerInterface::method()} return value.
 * Duplicate method registrations throw at compile / boot time.
 */
class Dispatcher
{
    /** @var array<string, HandlerInterface> */
    private array $handlers;

    /**
     * @param HandlerInterface[] $handlers
     * @param LoggerInterface $logger
     */
    public function __construct(
        array $handlers,
        private readonly LoggerInterface $logger
    ) {
        $this->handlers = [];
        foreach ($handlers as $handler) {
            if (!$handler instanceof HandlerInterface) {
                throw new InvalidArgumentException(sprintf(
                    'MCP JSON-RPC handlers must implement %s, got %s.',
                    HandlerInterface::class,
                    get_debug_type($handler)
                ));
            }
            $method = $handler->method();
            if (isset($this->handlers[$method])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate MCP JSON-RPC handler registration for method "%s".',
                    $method
                ));
            }
            $this->handlers[$method] = $handler;
        }
    }

    /**
     * Route a JSON-RPC request to its handler and wrap handler failures.
     *
     * @param Request $request
     * @param AuthenticatedContext $context
     * @return Response|null  Null for notifications (the server stays silent).
     */
    public function dispatch(Request $request, AuthenticatedContext $context): ?Response
    {
        $handler = $this->handlers[$request->method] ?? null;
        if ($handler === null) {
            if ($request->isNotification) {
                return null;
            }
            return Response::failure(
                $request->id,
                ErrorCode::METHOD_NOT_FOUND,
                sprintf('Method "%s" not found.', $request->method)
            );
        }

        try {
            return $handler->handle($request, $context);
        } catch (Throwable $e) {
            $this->logger->error('MCP JSON-RPC handler raised an exception.', [
                'method' => $request->method,
                'exception' => $e,
            ]);
            if ($request->isNotification) {
                return null;
            }
            return Response::failure($request->id, ErrorCode::INTERNAL_ERROR, 'Internal error.');
        }
    }
}
