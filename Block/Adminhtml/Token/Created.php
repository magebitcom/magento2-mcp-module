<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\Token;

use Magento\Backend\Block\Template;

/**
 * One-shot post-create plaintext bearer display. Plaintext is passed in by the
 * Save controller via {@see setData()}; never persisted to session storage.
 */
class Created extends Template
{
    public function getName(): string
    {
        $v = $this->getData('name');
        return is_string($v) ? $v : '';
    }

    public function getPlaintext(): string
    {
        $v = $this->getData('plaintext');
        return is_string($v) ? $v : '';
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('magebit_mcp/token/index');
    }
}
