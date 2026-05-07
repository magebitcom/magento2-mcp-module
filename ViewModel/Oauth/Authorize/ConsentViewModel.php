<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\ViewModel\Oauth\Authorize;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\OAuth\Client;
use Magento\Backend\Model\Auth;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\User\Model\User;

/**
 * Consent screen view model. Owns the per-tool jstree payload — the template
 * just escapes and renders. Lifts the previous template-level
 * `ObjectManager::getInstance()` lookups and the recursive `state.disabled`
 * mutator into testable ViewModel methods.
 */
class ConsentViewModel implements ArgumentInterface
{
    /**
     * @param ToolResourceTree $toolResourceTree
     * @param ToolRegistryInterface $toolRegistry
     * @param Auth $auth
     * @param Json $jsonSerializer
     */
    public function __construct(
        private readonly ToolResourceTree $toolResourceTree,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly Auth $auth,
        private readonly Json $jsonSerializer
    ) {
    }

    /**
     * Tree shape consumed by the jstree widget. Every node beyond the
     * client's allowed-tool cap renders disabled with a tooltip.
     *
     * @param Client|null $client
     * @return array<int, array<string, mixed>>
     */
    public function getTreeForRender(?Client $client): array
    {
        $tree = $this->toolResourceTree->build($this->resolveAdminUserId());
        $allowedAclIds = $this->buildAllowedAclIdSet($client);
        $this->applyClientAllowedFilter($tree, $allowedAclIds);
        return $tree;
    }

    /**
     * @param array<int, string> $preTickedTools
     * @return array<int, string>
     */
    public function getPreTickedAclIds(array $preTickedTools): array
    {
        if ($preTickedTools === []) {
            return [];
        }
        $aclIds = [];
        foreach ($this->toolRegistry->all() as $tool) {
            if (in_array($tool->getName(), $preTickedTools, true)) {
                $aclIds[] = $tool->getAclResource();
            }
        }
        return $aclIds;
    }

    /**
     * Pre-serialised mage-init payload ready to drop into `data-mage-init`.
     *
     * @param Client|null $client
     * @param array<int, string> $preTickedTools
     * @return string
     */
    public function getWidgetOptionsJson(?Client $client, array $preTickedTools): string
    {
        $options = [
            'mcpScopesTree' => [
                'treeUrl' => '',
                'editFormSelector' => '#mcp-oauth-consent-form',
                'initialTree' => $this->getTreeForRender($client),
                'initialSelection' => $this->getPreTickedAclIds($preTickedTools),
            ],
        ];
        $encoded = $this->jsonSerializer->serialize($options);
        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param Client|null $client
     * @return string
     */
    public function getClientLabel(?Client $client): string
    {
        return $client === null ? 'an MCP client' : $client->getName();
    }

    /**
     * Recursively disable any node not in the client's allowed-tool list.
     * Group / root nodes are left enabled — disabling a leaf cascades the
     * group's "any enabled child?" computation in the JS widget.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, true> $allowedAclIds
     */
    public function applyClientAllowedFilter(array &$nodes, array $allowedAclIds): void
    {
        $disabledTitle = __('Not requested by this client.')->__toString();
        foreach ($nodes as &$node) {
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            $isGroup = $id === ToolResourceTree::ROOT_RESOURCE_ID || str_starts_with($id, 'mcp_group_');
            if (!$isGroup && !isset($allowedAclIds[$id])) {
                if (!isset($node['state']) || !is_array($node['state'])) {
                    $node['state'] = [];
                }
                $node['state']['disabled'] = true;
                $node['a_attr'] = ['title' => $disabledTitle];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                /** @var array<int, array<string, mixed>> $children */
                $children = $node['children'];
                $this->applyClientAllowedFilter($children, $allowedAclIds);
                $node['children'] = $children;
            }
        }
    }

    /**
     * @param Client|null $client
     * @return array<string, true> ACL resource id → marker
     */
    public function buildAllowedAclIdSet(?Client $client): array
    {
        if ($client === null) {
            return [];
        }
        $allowedTools = $client->getAllowedTools();
        if ($allowedTools === []) {
            return [];
        }
        $set = [];
        foreach ($this->toolRegistry->all() as $tool) {
            if (in_array($tool->getName(), $allowedTools, true)) {
                $set[$tool->getAclResource()] = true;
            }
        }
        return $set;
    }

    /**
     * @return int|null
     */
    private function resolveAdminUserId(): ?int
    {
        $user = $this->auth->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $rawId = $user->getId();
        if (!is_scalar($rawId)) {
            return null;
        }
        $id = (int) $rawId;
        return $id > 0 ? $id : null;
    }
}
