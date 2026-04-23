<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ResourceModel\AuditEntry;

use Magebit\Mcp\Model\AuditEntry;
use Magebit\Mcp\Model\ResourceModel\AuditEntry as AuditEntryResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method AuditEntry[] getItems()
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    protected function _construct(): void
    {
        $this->_init(AuditEntry::class, AuditEntryResource::class);
    }
}
