<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Prompt;

use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\Prompt\AdminPromptRepository;
use Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Throwable;

/**
 * Bulk variant of {@see Delete}. Walks the filter-resolved collection and
 * deletes each prompt by id; failures are swallowed per-row so a single bad
 * row does not abort the batch.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_prompts';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param AdminPromptRepository $repository
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly AdminPromptRepository $repository
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
            if (!$item instanceof AdminPrompt) {
                continue;
            }
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            try {
                $this->repository->deleteById($id);
                $deleted++;
            } catch (Throwable $ignoredDeleteError) {
                unset($ignoredDeleteError);
            }
        }

        if ($deleted === 0) {
            $this->messageManager->addNoticeMessage((string) __('No prompts were deleted.'));
        } else {
            $this->messageManager->addSuccessMessage(
                (string) __('Deleted %1 prompt(s).', $deleted)
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
