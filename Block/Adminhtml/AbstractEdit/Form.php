<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\AbstractEdit;

use Magento\Backend\Block\Widget\Form\Generic;

/**
 * Empty `<form id="edit_form">` skeleton shared by every MCP admin edit page
 * (Token, OAuth Client). The Tabs widget moves rendered tab panels into this
 * form so all fields submit together.
 */
class Form extends Generic
{
    /**
     * @return $this
     */
    protected function _prepareForm(): self
    {
        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create([
            'data' => [
                'id' => 'edit_form',
                'action' => $this->getData('action'),
                'method' => 'post',
                'enctype' => 'multipart/form-data',
            ],
        ]);
        $form->setUseContainer(true);
        $this->setForm($form);
        return parent::_prepareForm();
    }
}
