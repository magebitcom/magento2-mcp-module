<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magento\User\Model\User;

/**
 * The single source of what the OAuth consent screen offers an admin.
 *
 * The consent view model renders {@see tree()}; {@see forAdmin()} reads the tool names back out of the
 * same tree, so "the admin ticked everything offered" can be recognised exactly. The tree differs from
 * "every registered tool the role allows": it only holds tool ACL resources nested under
 * `Magebit_Mcp::tools` (not the node itself, which the `system.job.*` tools use), and it renders one leaf
 * per ACL resource, which maps back to one tool when several tools share that resource.
 */
class ConsentOfferedTools
{
    /**
     * @param ToolResourceTree $toolResourceTree
     */
    public function __construct(
        private readonly ToolResourceTree $toolResourceTree
    ) {
    }

    /**
     * The consent tree for an admin, before any per-client filter.
     *
     * @param int|null $adminUserId
     * @return array<int, array<string, mixed>>
     */
    public function tree(?int $adminUserId): array
    {
        return $this->toolResourceTree->build($adminUserId);
    }

    /**
     * Canonical names of the tools the consent tree offers this admin (enabled nodes only), before any
     * per-client filter. Each name appears once, in tree order.
     *
     * @param User $admin
     * @return array<int, string>
     */
    public function forAdmin(User $admin): array
    {
        $rawId = $admin->getId();
        $adminUserId = is_scalar($rawId) ? (int) $rawId : 0;
        if ($adminUserId <= 0) {
            return [];
        }
        $toolsByAclId = $this->toolResourceTree->indexToolsByAclResource();
        $names = [];
        $this->collectOffered($this->tree($adminUserId), $toolsByAclId, $names);
        return array_keys($names);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string> $toolsByAclId
     * @param array<string, true> $names
     * @return void
     */
    private function collectOffered(array $nodes, array $toolsByAclId, array &$names): void
    {
        foreach ($nodes as $node) {
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            $state = $node['state'] ?? null;
            $enabled = !is_array($state) || empty($state['disabled']);
            if ($enabled && isset($toolsByAclId[$id])) {
                $names[$toolsByAclId[$id]] = true;
            }
            $children = $node['children'] ?? null;
            if (is_array($children) && $children !== []) {
                /** @var array<int, array<string, mixed>> $children */
                $this->collectOffered($children, $toolsByAclId, $names);
            }
        }
    }
}
