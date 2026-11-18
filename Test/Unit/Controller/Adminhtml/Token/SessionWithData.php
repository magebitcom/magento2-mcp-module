<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Adminhtml\Token;

use Magento\Backend\Model\Session;

class SessionWithData extends Session
{
    /**
     * Set session data.
     *
     * @param string|array $key
     * @param mixed $value
     * @return self
     */
    public function setData($key, $value = null): self
    {
        return $this;
    }
}
