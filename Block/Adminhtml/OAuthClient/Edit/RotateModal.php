<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\Adminhtml\OAuthClient\Edit;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Controller\Adminhtml\OAuthClient\Edit as EditController;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

class RotateModal extends Template
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return int|null
     */
    public function getOAuthClientId(): ?int
    {
        $client = $this->registry->registry(EditController::REGISTRY_KEY);
        if (!$client instanceof ClientInterface) {
            return null;
        }
        $id = $client->getId();
        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * @return string
     */
    public function getRotateUrl(): string
    {
        return $this->getUrl('magebit_mcp/oauthclient/rotatesecret');
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        if ($this->getOAuthClientId() === null) {
            return '';
        }
        return parent::_toHtml();
    }
}
