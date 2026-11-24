<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

/**
 * Token-scope and role rule for the `system.job.*` tools: they follow the jobs a token and a role can start.
 *
 * A literal grant never lists the job tools (the consent tree cannot offer them), so they pass the
 * token-scope check whenever the grant includes a bulk tool that can run as a background job. Likewise a role
 * passes their outer ACL check with their declared resource (`Magebit_Mcp::tools`) or with the resource of any
 * async-capable bulk tool, which may sit outside `Magebit_Mcp::tools`. This is computed on every request, never
 * stored. The underlying ACL, the write gate and the per-job checks still apply.
 */
class JobToolGate
{
    public const JOB_TOOL_PREFIX = 'system.job.';

    /** @var list<string>|null ACL resources of the async-capable bulk tools, collected once per instance. */
    private ?array $asyncToolResources = null;

    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker
    ) {
    }

    /**
     * The outer role check of `tools/list` and `tools/call`: the tool's own ACL resource, or, for a job tool,
     * the resource of at least one async-capable bulk tool.
     *
     * @param User $admin
     * @param ToolInterface $tool
     * @return bool
     */
    public function isRoleAllowed(User $admin, ToolInterface $tool): bool
    {
        if ($this->aclChecker->isAllowed($admin, $tool->getAclResource())) {
            return true;
        }
        if (!$this->isJobTool($tool->getName())) {
            return false;
        }
        foreach ($this->asyncToolResources() as $resource) {
            if ($this->aclChecker->isAllowed($admin, $resource)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $name Canonical (dotted) tool name.
     * @return bool
     */
    public function isJobTool(string $name): bool
    {
        return str_starts_with($name, self::JOB_TOOL_PREFIX);
    }

    /**
     * True for a wildcard grant (null), or when any granted tool is an async-capable bulk tool.
     *
     * @param array<int, string>|null $scopes
     * @return bool
     */
    public function allowedForScopes(?array $scopes): bool
    {
        if ($scopes === null) {
            return true;
        }
        foreach ($scopes as $name) {
            if (!$this->toolRegistry->has($name)) {
                continue;
            }
            try {
                $tool = $this->toolRegistry->get($name);
            } catch (NoSuchEntityException) {
                continue;
            }
            if ($tool instanceof BulkToolAdapter && $tool->isAsyncCapable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function asyncToolResources(): array
    {
        if ($this->asyncToolResources === null) {
            $resources = [];
            foreach ($this->toolRegistry->all() as $tool) {
                if ($tool instanceof BulkToolAdapter && $tool->isAsyncCapable()) {
                    $resources[$tool->getAclResource()] = true;
                }
            }
            $this->asyncToolResources = array_map('strval', array_keys($resources));
        }
        return $this->asyncToolResources;
    }
}
