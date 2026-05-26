<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt;

use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt as AdminPromptResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method AdminPrompt[] getItems()
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AdminPrompt::class, AdminPromptResource::class);
    }
}
