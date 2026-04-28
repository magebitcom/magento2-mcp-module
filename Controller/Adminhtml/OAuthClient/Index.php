<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin landing page for OAuth client management. Pops a one-shot plaintext
 * client_secret bundle out of the session as a warning message — not a success
 * message, which would imply the user can safely navigate away before copying
 * the secret. The bundle is set by {@see Save} on create and cleared on read.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $this->surfaceNewClientCredentialsIfAny();

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_oauth_clients');
        $resultPage->getConfig()->getTitle()->prepend((string) __('OAuth Clients'));
        return $resultPage;
    }

    private function surfaceNewClientCredentialsIfAny(): void
    {
        $bag = $this->_session->getData(Save::SESSION_KEY_PLAINTEXT, true);
        if (!is_array($bag)) {
            return;
        }
        $clientId = $bag['client_id'] ?? null;
        $clientSecret = $bag['client_secret'] ?? null;
        if (!is_string($clientId) || $clientId === ''
            || !is_string($clientSecret) || $clientSecret === ''
        ) {
            return;
        }

        $this->messageManager->addWarningMessage(
            (string) __(
                'OAuth client created. Copy these credentials NOW — the secret is not retrievable later. '
                . 'Client ID: %1 — Client Secret: %2',
                $clientId,
                $clientSecret
            )
        );
    }
}
