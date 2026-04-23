<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;

/**
 * Handles the `tools/list` JSON-RPC method.
 *
 * Returns every registered tool's public schema (name/title/description/inputSchema).
 * Phase 4 will filter the list by ACL against the authenticated admin user — today
 * all registered tools are visible to all callers.
 */
class ToolsListHandler implements HandlerInterface
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    public function method(): string
    {
        return 'tools/list';
    }

    public function handle(Request $request): Response
    {
        $tools = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'title' => $tool->getTitle(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return Response::success($request->id, ['tools' => $tools]);
    }
}
