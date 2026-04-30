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
 * One-shot post-create credentials display. Plaintext is passed in by the Save
 * controller via {@see setData()} and is never persisted to session storage —
 * it lives only in the in-flight response body.
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

    public function getBackUrl(): string
    {
        return $this->getUrl('magebit_mcp/oauthclient/index');
    }
}
