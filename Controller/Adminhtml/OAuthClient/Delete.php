<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * POST `magebit_mcp/oauthclient/delete/id/<n>` — hard-removes the row. Auth
 * codes and refresh tokens issued for the client are cascaded via the FK.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    /**
     * @param Context $context
     * @param ClientRepository $clientRepository
     */
    public function __construct(
        Context $context,
        private readonly ClientRepository $clientRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $raw = $this->getRequest()->getParam('id');
        if (!is_scalar($raw) || (int) $raw === 0) {
            $this->messageManager->addErrorMessage((string) __('Missing or invalid OAuth client id.'));
            return $redirect->setPath('*/*/index');
        }
        $id = (int) $raw;

        try {
            $this->clientRepository->deleteById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('OAuth client #%1 not found.', $id)
            );
            return $redirect->setPath('*/*/index');
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to delete OAuth client #%1: %2', $id, $e->getMessage())
            );
            return $redirect->setPath('*/*/index');
        }

        $this->messageManager->addSuccessMessage(
            (string) __('OAuth client #%1 deleted.', $id)
        );
        return $redirect->setPath('*/*/index');
    }
}
