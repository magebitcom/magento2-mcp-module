<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;

class ToolsListHandler implements HandlerInterface
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param ModuleConfig $config
     * @param SchemaSanitizer $schemaSanitizer
     * @param LoggerInterface $logger
     * @param ToolDomain $toolDomain
     * @param DisabledTools $disabledTools
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly ModuleConfig $config,
        private readonly SchemaSanitizer $schemaSanitizer,
        private readonly LoggerInterface $logger,
        private readonly ToolDomain $toolDomain,
        private readonly DisabledTools $disabledTools
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
        $hiddenByUnderlyingAcl = [];

        foreach ($this->toolRegistry->all() as $tool) {
            if ($this->disabledTools->isDisabled($tool->getName())) {
                continue;
            }
            if ($scopes !== null && !in_array($tool->getName(), $scopes, true)) {
                continue;
            }
            if ($tool->getWriteMode() === WriteMode::WRITE && !$writesAllowed) {
                continue;
            }
            if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
                continue;
            }
            // Mirrors the second gate in ToolsCallHandler: a tool the role cannot
            // invoke must not be advertised, or the client wastes a call on it.
            if ($this->isUnderlyingAclDenied($tool, $context)) {
                $hiddenByUnderlyingAcl[] = $tool->getName();
                continue;
            }

            $displayTitle = $this->toolDomain->prefixTitle($tool->getName(), $tool->getTitle());
            $tools[] = [
                'name' => str_replace('.', '_', $tool->getName()),
                'title' => $displayTitle,
                'description' => $tool->getDescription(),
                'inputSchema' => $this->schemaSanitizer->sanitize(
                    $tool->getName(),
                    $tool->getInputSchema()
                ),
                'annotations' => [
                    'title' => $displayTitle,
                    'readOnlyHint' => $tool->getWriteMode() === WriteMode::READ,
                    'destructiveHint' => $tool->getConfirmationRequired(),
                ],
            ];
        }

        $this->logger->debug(
            sprintf('Emitted tools/list with %d tool(s).', count($tools)),
            [
                'tools' => array_column($tools, 'name'),
                'hidden_by_underlying_acl' => $hiddenByUnderlyingAcl,
            ]
        );

        return Response::success($request->id, ['tools' => $tools]);
    }

    /**
     * @param ToolInterface $tool
     * @param AuthenticatedContext $context
     * @return bool
     */
    private function isUnderlyingAclDenied(ToolInterface $tool, AuthenticatedContext $context): bool
    {
        if (!$tool instanceof UnderlyingAclAwareInterface) {
            return false;
        }
        $underlying = $tool->getUnderlyingAclResource();

        return $underlying !== null
            && !$this->aclChecker->isAllowed($context->adminUser, $underlying);
    }
}
