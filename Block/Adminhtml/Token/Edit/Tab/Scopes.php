<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit\Tab;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Controller\Adminhtml\Token\Save;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Backend\Model\Session;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * "Resource Access" tab — renders an ACL jstree under `Magebit_Mcp::tools`.
 * Nodes the chosen admin user's role doesn't grant render disabled with a
 * tooltip; the form gate at runtime enforces the same intersection, so the
 * UI just makes the fact visible to the operator at mint time.
 */
class Scopes extends Template implements TabInterface
{
    /**
     * @param Context $context
     * @param ToolResourceTree $toolResourceTree
     * @param ToolRegistryInterface $toolRegistry
     * @param Session $backendSession
     * @param Json $jsonSerializer
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ToolResourceTree $toolResourceTree,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly Session $backendSession,
        private readonly Json $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getTabLabel()
    {
        return (string) __('Resource Access');
    }

    /**
     * @return string
     */
    public function getTabTitle()
    {
        return $this->getTabLabel();
    }

    public function canShowTab(): bool
    {
        return true;
    }

    public function isHidden(): bool
    {
        return false;
    }

    /**
     * Initial tree payload, keyed by admin user id pre-selected on render
     * (zero when no admin is chosen yet — every node disabled).
     *
     * @return string
     */
    public function getInitialTreeJson(): string
    {
        $tree = $this->toolResourceTree->build($this->getRestoredAdminUserId());
        $encoded = $this->jsonSerializer->serialize($tree);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * Translate restored tool-name scopes back to ACL ids so the picker
     * pre-checks them after a server-side validation bounce.
     *
     * @return string
     */
    public function getRestoredSelectionJson(): string
    {
        $scopes = $this->getRestoredScopes();
        if ($scopes === null) {
            return '[]';
        }
        $selected = [];
        foreach ($this->toolRegistry->all() as $tool) {
            if (in_array($tool->getName(), $scopes, true)) {
                $selected[] = $tool->getAclResource();
            }
        }
        $encoded = $this->jsonSerializer->serialize($selected);
        return is_string($encoded) ? $encoded : '[]';
    }

    public function isAllResourcesRestored(): bool
    {
        $restored = $this->backendSession->getData(Save::SESSION_KEY_FORM_DATA);
        if (!is_array($restored)) {
            return true;
        }
        return (string) ($restored['all_resources'] ?? '1') === '1';
    }

    public function hasRestoredAdminUser(): bool
    {
        return $this->getRestoredAdminUserId() !== null;
    }

    public function getResourceTreeUrl(): string
    {
        return $this->getUrl('magebit_mcp/token/resourceTree');
    }

    private function getRestoredAdminUserId(): ?int
    {
        $restored = $this->backendSession->getData(Save::SESSION_KEY_FORM_DATA);
        if (!is_array($restored)) {
            return null;
        }
        $value = $restored['admin_user_id'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function getRestoredScopes(): ?array
    {
        $restored = $this->backendSession->getData(Save::SESSION_KEY_FORM_DATA);
        if (!is_array($restored)) {
            return null;
        }
        $scopes = $restored['scopes_tool_names'] ?? null;
        if (!is_array($scopes)) {
            return null;
        }
        $out = [];
        foreach ($scopes as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }
        return $out === [] ? null : $out;
    }
}
