<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\Job\JobConnection;
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
 * Bulk variant of {@see Revoke}; revoke is idempotent so re-revoking selected
 * already-revoked rows is safe. The jobs of the connections it revoked are cancelled in one batched
 * {@see JobCanceller::cancelForConnections()} call after the loop, never with queries per token.
 */
class MassRevoke extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param TokenRepository $tokenRepository
     * @param JobCanceller $jobCanceller
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
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

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage((string) __('Invalid selection filter.'));
            return $redirect->setPath('*/*/index');
        }

        $revoked = 0;
        $connections = [];
        foreach ($collection->getItems() as $token) {
            if (!$token instanceof Token) {
                continue;
            }
            $id = $token->getId();
            if ($id === null) {
                continue;
            }
            try {
                $wasActive = $token->getRevokedAt() === null;
                $this->tokenRepository->revoke($id);
                $revoked++;
                // An already-revoked token's jobs were handled when it was revoked.
                $connection = $wasActive ? JobConnection::fromToken($token) : null;
                if ($connection !== null) {
                    $connections[] = $connection;
                }
            } catch (Throwable $ignoredRevokeError) {
                unset($ignoredRevokeError);
            }
        }
        // Never throws, so it cannot hide the revokes above.
        $cancelled = $connections === [] ? 0 : $this->jobCanceller->cancelForConnections($connections);

        if ($revoked === 0) {
            $this->messageManager->addNoticeMessage((string) __('No connections were revoked.'));
        } else {
            $message = (string) __('Revoked %1 connection(s).', $revoked);
            if ($cancelled > 0) {
                $message .= ' ' . (string) __('%1 background job(s) were cancelled.', $cancelled);
            }
            $this->messageManager->addSuccessMessage($message);
        }

        return $redirect->setPath('*/*/index');
    }
}
