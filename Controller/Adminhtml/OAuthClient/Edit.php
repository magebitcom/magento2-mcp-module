<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the OAuth client edit form. Both "new" (forwarded from {@see NewAction})
 * and "existing" (`?id=<n>`) modes flow through here. The loaded model is
 * registered under `current_oauth_client` so the form block can read it; null
 * means new-mode for the form's branch.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';
    public const REGISTRY_KEY = 'current_oauth_client';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly ClientRepository $clientRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $idRaw = $this->getRequest()->getParam('id');
        $id = is_scalar($idRaw) ? (int) $idRaw : 0;

        $client = null;
        if ($id > 0) {
            try {
                $client = $this->clientRepository->getById($id);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(
                    (string) __('OAuth client #%1 not found.', $id)
                );
                /** @var Redirect $redirect */
                $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                return $redirect->setPath('*/*/index');
            }
        }

        $this->registry->register(self::REGISTRY_KEY, $client, true);

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_oauth_clients');
        $resultPage->addBreadcrumb((string) __('MCP'), (string) __('MCP'));
        $resultPage->addBreadcrumb(
            (string) __('OAuth Clients'),
            (string) __('OAuth Clients'),
            $this->getUrl('magebit_mcp/oauthclient/index')
        );

        if ($client instanceof Client) {
            $resultPage->addBreadcrumb((string) __('Edit'), (string) __('Edit'));
            $resultPage->getConfig()->getTitle()->prepend(
                (string) __('Edit OAuth Client "%1"', $client->getName())
            );
        } else {
            $resultPage->addBreadcrumb((string) __('New'), (string) __('New'));
            $resultPage->getConfig()->getTitle()->prepend((string) __('New OAuth Client'));
        }

        return $resultPage;
    }
}
