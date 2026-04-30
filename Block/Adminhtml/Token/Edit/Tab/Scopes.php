<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit\Tab;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * "Resource Access" tab — ACL jstree under `Magebit_Mcp::tools`. Disables nodes
 * the chosen admin's role doesn't grant; the runtime gate enforces the same
 * intersection so the picker is just a visual aid.
 */
class Scopes extends Template implements TabInterface
{
    /**
     * @param Context $context
     * @param ToolResourceTree $toolResourceTree
     * @param ToolRegistryInterface $toolRegistry
     * @param FormDataPersistence $formDataPersistence
     * @param Json $jsonSerializer
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ToolResourceTree $toolResourceTree,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly FormDataPersistence $formDataPersistence,
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
     * Initial tree, keyed by the restored admin user id (zero = every node disabled).
     */
    public function getInitialTreeJson(): string
    {
        $tree = $this->toolResourceTree->build($this->resolveRestoredAdminUserId());
        $encoded = $this->jsonSerializer->serialize($tree);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * ACL ids for the previously-submitted tool-name selection so the picker
     * pre-checks them after a validation bounce.
     */
    public function getRestoredSelectionJson(): string
    {
        $scopes = $this->resolveRestoredScopes();
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
        $restored = $this->formDataPersistence->get();
        if (!is_array($restored)) {
            return true;
        }
        $value = $restored['all_resources'] ?? '1';
        return (is_scalar($value) ? (string) $value : '1') === '1';
    }

    public function hasRestoredAdminUser(): bool
    {
        return $this->resolveRestoredAdminUserId() !== null;
    }

    public function getResourceTreeUrl(): string
    {
        return $this->getUrl('magebit_mcp/token/resourceTree');
    }

    private function resolveRestoredAdminUserId(): ?int
    {
        $restored = $this->formDataPersistence->get();
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
    private function resolveRestoredScopes(): ?array
    {
        $restored = $this->formDataPersistence->get();
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
