<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * POST `magebit_mcp/token/delete/id/<n>` — hard-removes the row. Prefer
 * {@see Revoke} when compliance needs the `token_id` linkage preserved on
 * audit rows (`ON DELETE SET NULL`). A live token's background jobs are cancelled
 * first, while the job rows still point at it.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param TokenRepository $tokenRepository
     * @param JobCanceller $jobCanceller
     */
    public function __construct(
        Context $context,
        private readonly TokenRepository $tokenRepository,
        private readonly JobCanceller $jobCanceller
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
            $this->messageManager->addErrorMessage((string) __('Missing or invalid token id.'));
            return $redirect->setPath('*/*/index');
        }
        $id = (int) $raw;

        $cancelled = 0;
        try {
            $token = $this->tokenRepository->getById($id);
            // The FK clears job.token_id on delete, so cancel now. An already-revoked token's jobs were handled
            // when it was revoked. The canceller never throws, so it cannot block the delete.
            if ($token->getRevokedAt() === null) {
                $cancelled = $this->jobCanceller->cancelForRevokedToken($token);
            }
            $this->tokenRepository->deleteById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('Connection #%1 not found.', $id)
            );
            return $redirect->setPath('*/*/index');
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to delete connection #%1: %2', $id, $e->getMessage())
            );
            return $redirect->setPath('*/*/index');
        }

        $message = (string) __('Connection #%1 deleted.', $id);
        if ($cancelled > 0) {
            $message .= ' ' . (string) __('%1 background job(s) were cancelled.', $cancelled);
        }
        $this->messageManager->addSuccessMessage($message);
        return $redirect->setPath('*/*/index');
    }
}
