<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;

class ToolsListHandler implements HandlerInterface
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param ModuleConfig $config
     * @param SchemaSanitizer $schemaSanitizer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly ModuleConfig $config,
        private readonly SchemaSanitizer $schemaSanitizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function method(): string
    {
        return 'tools/list';
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, AuthenticatedContext $context): Response
    {
        $scopes = $context->token->getScopes();
        $writesAllowed = $this->config->isAllowWrites() && $context->token->getAllowWrites();
        $tools = [];

        foreach ($this->toolRegistry->all() as $tool) {
            if ($scopes !== null && !in_array($tool->getName(), $scopes, true)) {
                continue;
            }
            if ($tool->getWriteMode() === WriteMode::WRITE && !$writesAllowed) {
                continue;
            }
            if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
                continue;
            }
            $tools[] = [
                'name' => str_replace('.', '_', $tool->getName()),
                'title' => $tool->getTitle(),
                'description' => $tool->getDescription(),
                'inputSchema' => $this->schemaSanitizer->sanitize(
                    $tool->getName(),
                    $tool->getInputSchema()
                ),
            ];
        }

        $this->logger->debug(
            sprintf('Emitted tools/list with %d tool(s).', count($tools)),
            ['tools' => array_column($tools, 'name')]
        );

        return Response::success($request->id, ['tools' => $tools]);
    }
}
