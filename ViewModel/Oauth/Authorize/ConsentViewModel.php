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
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magento\Backend\Model\Auth;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;

/**
 * Consent-screen view model — owns the per-tool jstree payload so the template
 * just escapes and renders.
 */
class ConsentViewModel implements ArgumentInterface
{
    /**
     * @param ToolResourceTree $toolResourceTree
     * @param ToolRegistryInterface $toolRegistry
     * @param Auth $auth
     * @param Json $jsonSerializer
     * @param UserCollectionFactory $userCollectionFactory
     */
    public function __construct(
        private readonly ToolResourceTree $toolResourceTree,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly Auth $auth,
        private readonly Json $jsonSerializer,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * @param Client|null $client
     * @return bool
     */
    public function isSharedMode(?Client $client): bool
    {
        return $client !== null && $client->getAuthMode() === AuthMode::SHARED;
    }

    /**
     * @param Client|null $client
     * @return string|null Pinned service admin display name; null outside shared mode
     *                    or when the admin row is gone.
     */
    public function getServiceAdminDisplayName(?Client $client): ?string
    {
        if ($client === null || $client->getAuthMode() !== AuthMode::SHARED) {
            return null;
        }
        $userId = $client->getServiceAdminUserId();
        if ($userId === null || $userId <= 0) {
            return null;
        }
        // Collection avoids Model::load() while admin_user has no service contract.
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('user_id', ['eq' => $userId]);
        $user = $collection->getFirstItem();
        if (!($user instanceof User) || $user->getId() === null) {
            return null;
        }
        $username = self::scalarToString($user->getData('username'));
        $firstName = self::scalarToString($user->getData('firstname'));
        $lastName = self::scalarToString($user->getData('lastname'));
        $fullName = trim($firstName . ' ' . $lastName);
        return $fullName !== ''
            ? sprintf('%s (%s)', $fullName, $username)
            : $username;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param Client|null $client
     * @return array<int, array<string, mixed>> jstree payload — nodes outside the
     *                    client's allowed-tool cap render disabled.
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
     * @param Client|null $client
     * @param array<int, string> $preTickedTools
     * @return string Serialized mage-init payload for `data-mage-init`.
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
     * Recursively disable leaves not in $allowedAclIds; group/root nodes stay enabled.
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
        $wildcard = ToolGrantResolver::isWildcard($allowedTools);
        $set = [];
        foreach ($this->toolRegistry->all() as $tool) {
            if ($wildcard || in_array($tool->getName(), $allowedTools, true)) {
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
