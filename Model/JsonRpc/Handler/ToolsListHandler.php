<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;

/**
 * Handles the `tools/list` JSON-RPC method.
 *
 * Returns only tools the authenticated admin's role grants AND the token's
 * scope (if any) permits. Tokens narrow — an admin with broad ACL can still
 * hand out a token that exposes only a single tool to a specific AI client.
 */
class ToolsListHandler implements HandlerInterface
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker
    ) {
    }

    public function method(): string
    {
        return 'tools/list';
    }

    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        $scopes = $context->token->getScopes();
        $tools = [];

        foreach ($this->toolRegistry->all() as $tool) {
            if ($scopes !== null && !in_array($tool->getName(), $scopes, true)) {
                continue;
            }
            if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
                continue;
            }
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
