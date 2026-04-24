<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Throwable;

/**
 * Bulk variant of {@see Delete} — hard-removes the selected rows. See
 * {@see Delete} for the trade-off against {@see Revoke}.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param TokenRepository $tokenRepository
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage((string) __('Invalid selection filter.'));
            return $redirect->setPath('*/*/index');
        }

        $deleted = 0;
        foreach ($collection->getItems() as $token) {
            if (!$token instanceof Token) {
                continue;
            }
            $id = $token->getId();
            if ($id === null) {
                continue;
            }
            try {
                $this->tokenRepository->deleteById($id);
                $deleted++;
            } catch (Throwable $ignoredDeleteError) {
                // Continue; aggregate reported below.
                unset($ignoredDeleteError);
            }
        }

        if ($deleted === 0) {
            $this->messageManager->addNoticeMessage((string) __('No connections were deleted.'));
        } else {
            $this->messageManager->addSuccessMessage(
                (string) __('Deleted %1 connection(s).', $deleted)
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
