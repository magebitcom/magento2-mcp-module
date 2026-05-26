<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient;

use Magento\Backend\Block\Template;

/**
 * One-shot post-create credentials display; plaintext lives only in the in-flight response.
 */
class Created extends Template
{
    public function getClientId(): string
    {
        $v = $this->getData('client_id');
        return is_string($v) ? $v : '';
    }

    public function getClientSecret(): string
    {
        $v = $this->getData('client_secret');
        return is_string($v) ? $v : '';
    }

    public function getName(): string
    {
        $v = $this->getData('name');
        return is_string($v) ? $v : '';
    }

    /**
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('magebit_mcp/oauthclient/index');
    }

    /**
     * @return bool True when the page is being rendered after a secret rotation;
     *              the template uses this to switch the post-action copy.
     */
    public function getIsRotation(): bool
    {
        return (bool) $this->getData('is_rotation');
    }
}
