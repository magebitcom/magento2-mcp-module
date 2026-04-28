<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Form\Container for the OAuth client New / Edit pages. Pairs with
 * {@see Edit\Form} which renders the actual fields.
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
     * Pre-add the form child under the canonical class name so that
     * {@see Container::_prepareLayout()}'s auto-discovery (which would
     * compute a Pascal-cased `Oauthclient` segment from `$_controller`)
     * is short-circuited. Our directory is `OAuthClient` (PascalCase,
     * matching the OAuth proper noun).
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        if (!$this->_layout->getChildName($this->_nameInLayout, 'form')) {
            $this->addChild('form', Edit\Form::class);
        }
        return parent::_prepareLayout();
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
}
