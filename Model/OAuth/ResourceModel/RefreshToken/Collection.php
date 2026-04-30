<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth\ResourceModel\RefreshToken;

use Magebit\Mcp\Model\OAuth\RefreshToken;
use Magebit\Mcp\Model\OAuth\ResourceModel\RefreshToken as RefreshTokenResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method RefreshToken[] getItems()
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
        $this->_init(RefreshToken::class, RefreshTokenResource::class);
    }
}
