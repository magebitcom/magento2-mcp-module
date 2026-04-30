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
use Magebit\Mcp\Model\OAuth\ResourceModel\Client\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Throwable;

/**
 * Bulk variant of {@see Delete}. Walks the filter-resolved collection and
 * deletes each client by id; failures are swallowed per-row so a single
 * bad row does not abort the batch.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ClientRepository $clientRepository
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
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

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (Throwable $ignoredFilterError) {
            unset($ignoredFilterError);
            $this->messageManager->addErrorMessage((string) __('Invalid selection filter.'));
            return $redirect->setPath('*/*/index');
        }

        $deleted = 0;
        foreach ($collection->getItems() as $item) {
            if (!$item instanceof Client) {
                continue;
            }
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            try {
                $this->clientRepository->deleteById($id);
                $deleted++;
            } catch (Throwable $ignoredDeleteError) {
                unset($ignoredDeleteError);
            }
        }

        if ($deleted === 0) {
            $this->messageManager->addNoticeMessage((string) __('No OAuth clients were deleted.'));
        } else {
            $this->messageManager->addSuccessMessage(
                (string) __('Deleted %1 OAuth client(s).', $deleted)
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
