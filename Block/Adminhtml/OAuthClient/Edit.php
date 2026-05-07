<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Block\Adminhtml\OAuthClient\Edit\Form;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Form\Container for the OAuth client New / Edit pages. Pairs with the Tabs
 * widget in the `left` container so the rendered tab panels are moved into
 * this form's `<form id="edit_form">` element by mage/backend/tabs.js.
 */
class Edit extends Container
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct(): void
    {
        $this->_objectId = 'id';
        $this->_controller = 'adminhtml_oauthclient';
        $this->_blockGroup = 'Magebit_Mcp';
        parent::_construct();

        $this->buttonList->remove('reset');
        $this->buttonList->remove('delete');
        $this->buttonList->update('save', 'label', __('Save Client'));
        $this->buttonList->update('back', 'label', __('Back'));
        $this->buttonList->update('back', 'onclick', sprintf(
            "location.href = '%s';",
            $this->getUrl('magebit_mcp/oauthclient/index')
        ));
    }

    /**
     * @return string
     */
    public function getHeaderText()
    {
        $client = $this->registry->registry(EditController::REGISTRY_KEY);
        if ($client instanceof ClientInterface) {
            return (string) __('Edit OAuth Client "%1"', $client->getName());
        }
        return (string) __('New OAuth Client');
    }

    public function getFormActionUrl(): string
    {
        return $this->getUrl('magebit_mcp/oauthclient/save');
    }

    /**
     * @return string
     */
    protected function _buildFormClassName()
    {
        // NameBuilder lowercases all but the first char of each part,
        // so _controller='adminhtml_oauthclient' would resolve to
        // Adminhtml\Oauthclient — which doesn't match this module's
        // OAuthClient directory on case-sensitive filesystems. Without
        // this override the form child silently fails to resolve,
        // leaving tab panels stranded in the sidebar.
        return Form::class;
    }
}
