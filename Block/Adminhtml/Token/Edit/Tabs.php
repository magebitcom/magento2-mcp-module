<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token\Edit;

use Magento\Backend\Block\Widget\Tabs as WidgetTabs;

/**
 * Sidebar tabs for the New MCP Connection form. mage/backend/tabs.js moves
 * tab panels into `#edit_form` so all fields submit together with the form.
 */
class Tabs extends WidgetTabs
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('magebit_mcp_token_edit_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle((string) __('Token Settings'));
    }
}
