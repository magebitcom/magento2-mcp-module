<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use stdClass;

/**
 * Handles the `initialize` JSON-RPC method.
 *
 * Advertises only the `tools` capability for the PoC; `resources`, `prompts`,
 * `sampling`, `logging` etc. are deliberately omitted.
 */
class InitializeHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $protocolVersion = '2025-06-18',
        private readonly string $serverName = 'Magebit MCP',
        private readonly string $serverVersion = '0.1.0'
    ) {
    }

    public function method(): string
    {
        return 'initialize';
    }

    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        unset($context);
        return Response::success($request->id, [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => [
                'tools' => new stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ]);
    }
}
