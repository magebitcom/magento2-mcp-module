<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit\Tab;

use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magebit\Mcp\Ui\Component\Form\AdminUser\Options as AdminUserOptions;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * "Token Info" tab — admin user, name, expires_at, allow_writes. Re-renders the
 * last-submitted values on a server-side validation bounce.
 */
class Info extends Generic implements TabInterface
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param AdminUserOptions $adminUserOptions
     * @param FormDataPersistence $formDataPersistence
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly AdminUserOptions $adminUserOptions,
        private readonly FormDataPersistence $formDataPersistence,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @return string
     */
    public function getTabLabel()
    {
        return (string) __('Token Info');
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
     * @return $this
     */
    protected function _prepareForm(): self
    {
        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create();
        $form->setHtmlIdPrefix('magebit_mcp_token_');

        $fieldset = $form->addFieldset('base_fieldset', [
            'legend' => __('Token Info'),
        ]);

        $fieldset->addField('admin_user_id', 'select', [
            'name' => 'admin_user_id',
            'label' => __('Admin User'),
            'title' => __('Admin User'),
            'required' => true,
            'values' => $this->adminUserOptions->toOptionArray(),
            'note' => __(
                'Token ACL intersects with this admin\'s role at every request — deactivating'
                . ' the admin revokes the token implicitly.'
            ),
        ]);

        $fieldset->addField('name', 'text', [
            'name' => 'name',
            'label' => __('Name'),
            'title' => __('Name'),
            'required' => true,
            'class' => 'validate-length maximum-length-128',
            'maxlength' => 128,
            'note' => __('A human-readable label, e.g. "Claude Desktop, laptop".'),
        ]);

        $fieldset->addField('expires_at', 'date', [
            'name' => 'expires_at',
            'label' => __('Expires At (UTC)'),
            'title' => __('Expires At (UTC)'),
            'date_format' => 'y-MM-dd',
            'time_format' => 'HH:mm:ss',
            'note' => __(
                'Leave blank for a non-expiring token. Revoke manually when the device is retired.'
            ),
        ]);

        $fieldset->addField('allow_writes', 'select', [
            'name' => 'allow_writes',
            'label' => __('Allow Write Tools'),
            'title' => __('Allow Write Tools'),
            'options' => [
                '1' => __('Yes'),
                '0' => __('No'),
            ],
            'note' => __(
                'Also requires the global magebit_mcp/general/allow_writes kill-switch to be on.'
            ),
        ]);

        $form->setValues($this->getRestoredValues());
        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * @return array<string, mixed>
     */
    private function getRestoredValues(): array
    {
        $defaults = [
            'admin_user_id' => '',
            'name' => '',
            'expires_at' => '',
            'allow_writes' => '1',
        ];

        $restored = $this->formDataPersistence->get();
        if ($restored === null) {
            return $defaults;
        }

        return array_merge($defaults, [
            'admin_user_id' => isset($restored['admin_user_id']) && is_scalar($restored['admin_user_id'])
                ? (string) $restored['admin_user_id']
                : '',
            'name' => isset($restored['name']) && is_scalar($restored['name'])
                ? (string) $restored['name']
                : '',
            'expires_at' => isset($restored['expires_at']) && is_scalar($restored['expires_at'])
                ? (string) $restored['expires_at']
                : '',
            'allow_writes' => isset($restored['allow_writes']) && is_scalar($restored['allow_writes'])
                ? (string) (int) (bool) $restored['allow_writes']
                : '1',
        ]);
    }
}
