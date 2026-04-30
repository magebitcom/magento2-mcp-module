<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\AbstractEdit;

use Magento\Backend\Block\Widget\Tabs as WidgetTabs;

/**
 * Sidebar tabs container shared by every MCP admin edit page. The DOM id and
 * tab title are passed in via layout `<arguments>` so the block class is
 * reusable. mage/backend/tabs.js moves tab panels into `#edit_form` so all
 * fields submit together with the form.
 */
class Tabs extends WidgetTabs
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId($this->resolveData('tab_id', 'magebit_mcp_edit_tabs'));
        $this->setDestElementId('edit_form');
        $this->setTitle($this->resolveData('tab_title', (string) __('Settings')));
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private function resolveData(string $key, string $default): string
    {
        $value = $this->getData($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return $default;
    }
}
