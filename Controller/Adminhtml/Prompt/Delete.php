<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Prompt;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Prompt\AdminPromptRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use Throwable;

/**
 * POST `magebit_mcp/prompt/delete/id/<n>` — hard-removes the row.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_prompts';

    /**
     * @param Context $context
     * @param AdminPromptRepository $repository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly AdminPromptRepository $repository,
        private readonly LoggerInterface $logger
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
            $this->messageManager->addErrorMessage((string) __('Missing or invalid prompt id.'));
            return $redirect->setPath('*/*/index');
        }
        $id = (int) $raw;

        $name = null;
        try {
            $prompt = $this->repository->getById($id);
            $name = $prompt->getName();
            $this->repository->deleteById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('Prompt #%1 not found.', $id)
            );
            return $redirect->setPath('*/*/index');
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to delete prompt #%1: %2', $id, $e->getMessage())
            );
            return $redirect->setPath('*/*/index');
        }

        $this->logger->info('Admin prompt deleted.', [
            'prompt_name' => $name,
            'admin_user_id' => $this->resolveAdminUserId(),
            'action' => 'delete',
        ]);

        $this->messageManager->addSuccessMessage(
            (string) __('Prompt #%1 deleted.', $id)
        );
        return $redirect->setPath('*/*/index');
    }

    /**
     * @return int|null
     */
    private function resolveAdminUserId(): ?int
    {
        $user = $this->_auth->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $raw = $user->getId();
        return is_scalar($raw) ? (int) $raw : null;
    }
}
