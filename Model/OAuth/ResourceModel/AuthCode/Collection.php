<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth\ResourceModel\AuthCode;

use Magebit\Mcp\Model\OAuth\AuthCode;
use Magebit\Mcp\Model\OAuth\ResourceModel\AuthCode as AuthCodeResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method AuthCode[] getItems()
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(AuthCode::class, AuthCodeResource::class);
    }
}
