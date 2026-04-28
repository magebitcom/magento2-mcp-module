<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Block\OAuth\Authorize;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\View\Element\Template;

/**
 * Backing block for both the consent screen and the "log in to admin first"
 * screen rendered by {@see \Magebit\Mcp\Controller\OAuth\Authorize}. Carries
 * the validated OAuth query parameters (set on the block by the controller)
 * to the templates so they can be echoed back into hidden form fields.
 */
class Consent extends Template
{
    /**
     * @param Template\Context $context
     * @param BackendUrl $backendUrl
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly BackendUrl $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getAdminLoginUrl(): string
    {
        return $this->backendUrl->getUrl('adminhtml/auth/login');
    }

    /**
     * @return array<string, mixed>
     */
    public function getOAuthParams(): array
    {
        $params = $this->getData('oauth_params');
        return is_array($params) ? $params : [];
    }

    public function getCurrentUrl(): string
    {
        $value = $this->getData('current_url');
        return is_string($value) ? $value : '';
    }
}
