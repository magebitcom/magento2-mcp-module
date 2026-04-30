<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Helper\Acl;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Builds the jstree-compatible tree of MCP tool ACL resources, intersected
 * with the chosen admin user's allowed resource set so the picker can mark
 * scopes the admin's role doesn't grant as disabled.
 *
 * Walks the Laminas ACL graph via `Acl::isAllowed()` rather than comparing
 * against `AclRetriever::getAllowedResourcesByUser()`'s output — that helper
 * returns only the *explicit* rule rows on the role, so a full-admin role
 * (single `Magento_Backend::all` rule + inheritance) collapses to one
 * resource and everything else looks denied.
 */
class ToolResourceTree
{
    public const ROOT_RESOURCE_ID = 'Magebit_Mcp::tools';

    /**
     * @param ProviderInterface $resourceProvider
     * @param AclChecker $aclChecker
     * @param AdminUserLookup $adminUserLookup
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly ProviderInterface $resourceProvider,
        private readonly AclChecker $aclChecker,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * @param int|null $adminUserId
     * @return array<int, array<string, mixed>>
     */
    public function build(?int $adminUserId): array
    {
        return $this->buildTree($this->resolveAclRole($adminUserId), allowAll: false);
    }

    /**
     * Build the tree without any per-admin ACL filter — every node renders
     * enabled. Used by admin-agnostic forms (OAuth client edit) where the
     * stored selection caps what can be requested at consent time, and the
     * consent screen is what intersects with a specific admin's role.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildUnrestricted(): array
    {
        return $this->buildTree(null, allowAll: true);
    }

    /**
     * @param string|null $aclRole
     * @param bool $allowAll
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(?string $aclRole, bool $allowAll): array
    {
        $toolsNode = $this->locateToolsNode($this->resourceProvider->getAclResources());
        if ($toolsNode === null) {
            return [];
        }
        $rawChildren = $toolsNode['children'] ?? null;
        if (!is_array($rawChildren) || $rawChildren === []) {
            return [];
        }

        $toolsByAclId = $this->indexToolsByAclResource();

        // Group leaf tool nodes by the first dot-segment of their tool name
        // (e.g. `catalog.product.get` → "catalog"). Non-tool ACL children stay
        // top-level so future intermediate-grouping ACL hierarchies still work.
        $groups = [];
        $standalone = [];
        foreach ($rawChildren as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
            $toolName = $toolsByAclId[$childId] ?? null;
            $hasOwnChildren = !empty($child['children']) && is_array($child['children']);

            if ($toolName === null || $hasOwnChildren) {
                $standalone[] = $this->mapNode($child, $aclRole, $allowAll);
                continue;
            }

            $groupKey = $this->groupKeyForToolName($toolName);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'id' => 'mcp_group_' . $groupKey,
                    'text' => $this->groupLabel($groupKey),
                    'state' => ['opened' => false, 'disabled' => true],
                    'a_attr' => new \stdClass(),
                    'children' => [],
                ];
            }
            $groups[$groupKey]['children'][] = $this->mapNode($child, $aclRole, $allowAll);
        }

        ksort($groups);
        $output = [];
        foreach ($groups as $group) {
            $children = $group['children'];
            $anyEnabled = false;
            foreach ($children as $childItem) {
                if (!is_array($childItem)) {
                    continue;
                }
                $childState = $childItem['state'] ?? null;
                if (is_array($childState) && empty($childState['disabled'])) {
                    $anyEnabled = true;
                    break;
                }
            }
            // Group is selectable when at least one child is granted by the
            // admin's role — the JS cascade then selects every enabled leaf.
            // Group ids themselves never round-trip to scopes_json (Save
            // strips `mcp_group_*` prefixes), so picking one is shorthand for
            // "all enabled tools in this category".
            $group['state']['disabled'] = !$anyEnabled;
            $group['state']['opened'] = $anyEnabled;
            $group['a_attr'] = $anyEnabled
                ? new \stdClass()
                : ['title' => (string) __('No tools in this group are granted by the selected admin user\'s role.')];
            $output[] = $group;
        }
        foreach ($standalone as $item) {
            $output[] = $item;
        }

        return $output;
    }

    /**
     * @param string $toolName
     * @return string
     */
    private function groupKeyForToolName(string $toolName): string
    {
        $dotIdx = strpos($toolName, '.');
        if ($dotIdx === false || $dotIdx === 0) {
            return '_other';
        }
        return substr($toolName, 0, $dotIdx);
    }

    /**
     * @param string $key
     * @return string
     */
    private function groupLabel(string $key): string
    {
        if ($key === '_other') {
            return (string) __('Other');
        }
        return ucfirst($key);
    }

    /**
     * @return array<string, string> ACL resource ID → tool name
     */
    private function indexToolsByAclResource(): array
    {
        $map = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $map[$tool->getAclResource()] = $tool->getName();
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<string, mixed>|null
     */
    private function locateToolsNode(array $resources): ?array
    {
        foreach ($resources as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) === self::ROOT_RESOURCE_ID) {
                return $node;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $found = $this->locateToolsNode($node['children']);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param string|null $aclRole
     * @param bool $allowAll
     * @return array<string, mixed>
     */
    private function mapNode(array $node, ?string $aclRole, bool $allowAll): array
    {
        $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
        $title = isset($node['title']) && is_scalar($node['title']) ? (string) $node['title'] : $id;

        $isAllowed = $allowAll || $this->isAllowedForRole($aclRole, $id);

        $item = [
            'id' => $id,
            'text' => (string) __($title),
            'state' => [
                'opened' => true,
                'disabled' => !$isAllowed,
            ],
            'a_attr' => $isAllowed
                ? new \stdClass()
                : [
                    'title' => $aclRole !== null
                        ? (string) __(
                            'The selected admin user\'s role does not include this scope. '
                            . 'Update the admin user\'s role first.'
                        )
                        : (string) __('Pick an admin user first to enable scopes.'),
                ],
        ];

        if (!empty($node['children']) && is_array($node['children'])) {
            $children = [];
            foreach ($node['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $children[] = $this->mapNode($child, $aclRole, $allowAll);
            }
            $item['children'] = $children;
        }

        return $item;
    }

    /**
     * @param int|null $adminUserId
     * @return string|null
     */
    private function resolveAclRole(?int $adminUserId): ?string
    {
        if ($adminUserId === null || $adminUserId <= 0) {
            return null;
        }
        try {
            $user = $this->adminUserLookup->getById($adminUserId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $role = $user->getAclRole();
        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * @param string|null $aclRole
     * @param string $resourceId
     * @return bool
     */
    private function isAllowedForRole(?string $aclRole, string $resourceId): bool
    {
        return $aclRole === null ? false : $this->aclChecker->isAllowedForRole($aclRole, $resourceId);
    }
}
