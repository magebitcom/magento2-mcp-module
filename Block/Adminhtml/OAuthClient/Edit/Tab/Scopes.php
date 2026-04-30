<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient\Edit\Tab;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * "Allowed Tools" tab — renders the same jstree picker as the Connections
 * (token) form, but admin-agnostic: every tool node is enabled. The stored
 * selection is the upper bound a client may request; the consent screen
 * narrows further at runtime against the consenting admin's role.
 */
class Scopes extends Template implements TabInterface
{
    /**
     * @param Context $context
     * @param ToolResourceTree $toolResourceTree
     * @param ToolRegistryInterface $toolRegistry
     * @param Registry $registry
     * @param FormDataPersistence $formDataPersistence
     * @param Json $jsonSerializer
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ToolResourceTree $toolResourceTree,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly Registry $registry,
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
        return (string) __('Allowed Tools');
    }

    /**
     * @return string
     */
    public function getTabTitle()
    {
        return $this->getTabLabel();
    }

    /**
     * @return bool
     */
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return false;
    }

    /**
     * @return string
     */
    public function getInitialTreeJson(): string
    {
        $tree = $this->toolResourceTree->buildUnrestricted();
        $encoded = $this->jsonSerializer->serialize($tree);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * Translate the loaded client's allowed tool names back to ACL ids so the
     * picker pre-checks them (or the restored session payload after a
     * validation bounce).
     *
     * @return string
     */
    public function getRestoredSelectionJson(): string
    {
        $tools = $this->resolveSelectedTools();
        if ($tools === []) {
            return '[]';
        }
        $selected = [];
        foreach ($this->toolRegistry->all() as $tool) {
            if (in_array($tool->getName(), $tools, true)) {
                $selected[] = $tool->getAclResource();
            }
        }
        $encoded = $this->jsonSerializer->serialize($selected);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * @return string
     */
    public function getResourceTreeUrl(): string
    {
        return $this->getUrl('magebit_mcp/oauthclient/resourceTree');
    }

    /**
     * Bounced payload wins over the persisted client row — that's what makes
     * a validation bounce restore the operator's last selection.
     *
     * @return array<int, string>
     */
    private function resolveSelectedTools(): array
    {
        $restored = $this->formDataPersistence->get();
        if (is_array($restored) && isset($restored['allowed_tools']) && is_array($restored['allowed_tools'])) {
            $tools = [];
            foreach ($restored['allowed_tools'] as $name) {
                if (is_string($name) && $name !== '') {
                    $tools[] = $name;
                }
            }
            return $tools;
        }

        $client = $this->registry->registry(EditController::REGISTRY_KEY);
        if ($client instanceof ClientInterface) {
            return $client->getAllowedTools();
        }
        return [];
    }
}
