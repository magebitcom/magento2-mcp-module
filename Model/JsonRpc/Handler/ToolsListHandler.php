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
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\JsonRpc\StructuredOutputPolicy;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\ToolAvailability;
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
     * @param ToolAvailability|null $toolAvailability Optional so existing callers keep working; wired in di.xml
     * @param AuditContext|null $auditContext Optional; wired in di.xml (shared request-scoped instance)
     * @param StructuredOutputPolicy|null $structuredOutputPolicy Optional; wired in di.xml, null never emits
     * @param JobToolGate|null $jobToolGate Optional; wired in di.xml, null leaves job tools to the literal scope
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly ModuleConfig $config,
        private readonly SchemaSanitizer $schemaSanitizer,
        private readonly LoggerInterface $logger,
        private readonly ToolDomain $toolDomain,
        private readonly DisabledTools $disabledTools,
        private readonly ?ToolAvailability $toolAvailability = null,
        private readonly ?AuditContext $auditContext = null,
        private readonly ?StructuredOutputPolicy $structuredOutputPolicy = null,
        private readonly ?JobToolGate $jobToolGate = null
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
        $unavailable = [];
        $jobToolsAllowed = null;
        $structuredSupported = $this->auditContext !== null
            && $this->structuredOutputPolicy !== null
            && $this->structuredOutputPolicy->isSupported($this->auditContext->protocolVersion);

        foreach ($this->toolRegistry->all() as $tool) {
            if ($this->disabledTools->isDisabled($tool->getName())) {
                continue;
            }
            if ($this->toolAvailability !== null && !$this->toolAvailability->isAvailable($tool)) {
                $unavailable[] = $tool->getName();
                continue;
            }
            if ($scopes !== null && !in_array($tool->getName(), $scopes, true)) {
                // Job tools follow the jobs the grant can start (JobToolGate); computed once per list.
                $isAllowedJobTool = $this->jobToolGate !== null
                    && $this->jobToolGate->isJobTool($tool->getName())
                    && ($jobToolsAllowed ??= $this->jobToolGate->allowedForScopes($scopes));
                if (!$isAllowedJobTool) {
                    continue;
                }
            }
            if ($tool->getWriteMode() === WriteMode::WRITE && !$writesAllowed) {
                continue;
            }
            if (!$this->isRoleAllowed($tool, $context)) {
                continue;
            }
            // Mirrors the second gate in ToolsCallHandler: a tool the role cannot
            // invoke must not be advertised, or the client wastes a call on it.
            if ($this->isUnderlyingAclDenied($tool, $context)) {
                $hiddenByUnderlyingAcl[] = $tool->getName();
                continue;
            }

            $displayTitle = $this->toolDomain->prefixTitle($tool->getName(), $tool->getTitle());
            $entry = [
                'name' => str_replace('.', '_', $tool->getName()),
                'title' => $displayTitle,
                'description' => $tool->getDescription(),
                'inputSchema' => $this->schemaSanitizer->sanitizeForClient(
                    $tool->getName(),
                    $tool->getInputSchema()
                ),
                'annotations' => [
                    'title' => $displayTitle,
                    'readOnlyHint' => $tool->getWriteMode() === WriteMode::READ,
                    'destructiveHint' => $tool->getConfirmationRequired(),
                ],
            ];
            if ($structuredSupported && $tool instanceof BulkToolAdapter) {
                $entry['outputSchema'] = $tool->getOutputSchema();
            }
            $tools[] = $entry;
        }

        $this->logger->debug(
            sprintf('Emitted tools/list with %d tool(s).', count($tools)),
            [
                'tools' => array_column($tools, 'name'),
                'hidden_by_underlying_acl' => $hiddenByUnderlyingAcl,
                'unavailable' => $unavailable,
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

    /**
     * Outer role check; job tools also pass with an async-capable bulk tool's resource (JobToolGate).
     *
     * @param ToolInterface $tool
     * @param AuthenticatedContext $context
     * @return bool
     */
    private function isRoleAllowed(ToolInterface $tool, AuthenticatedContext $context): bool
    {
        return $this->jobToolGate !== null
            ? $this->jobToolGate->isRoleAllowed($context->adminUser, $tool)
            : $this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource());
    }
}
