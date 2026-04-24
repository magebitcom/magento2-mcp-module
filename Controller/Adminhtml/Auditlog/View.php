<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Auditlog;

use Magebit\Mcp\Model\AuditEntryFactory;
use Magebit\Mcp\Model\ResourceModel\AuditEntry as AuditEntryResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the detail page for one MCP audit log row. Rows are write-once;
 * missing / purged ids redirect back to the listing with an error message
 * instead of rendering a broken page.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_audit';
    public const REGISTRY_KEY = 'magebit_mcp_audit_entry';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly AuditEntryFactory $auditEntryFactory,
        private readonly AuditEntryResource $auditEntryResource,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $rawId = $this->getRequest()->getParam('id');
        $id = is_scalar($rawId) ? (int) $rawId : 0;
        if ($id <= 0) {
            return $this->redirectToIndex((string) __('Audit log entry id is missing.'));
        }

        $entry = $this->auditEntryFactory->create();
        $this->auditEntryResource->load($entry, $id);
        if ($entry->getId() === null) {
            return $this->redirectToIndex(
                (string) __('Audit log entry #%1 could not be found. It may have been purged.', $id)
            );
        }

        $this->registry->register(self::REGISTRY_KEY, $entry, true);

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_audit');
        $resultPage->addBreadcrumb((string) __('MCP'), (string) __('MCP'));
        $resultPage->addBreadcrumb((string) __('Audit Log'), (string) __('Audit Log'));
        $resultPage->addBreadcrumb(
            (string) __('Entry #%1', $id),
            (string) __('Entry #%1', $id)
        );
        $resultPage->getConfig()->getTitle()->prepend(
            (string) __('Audit Log Entry #%1', $id)
        );

        return $resultPage;
    }

    /**
     * @param string $message
     * @return Redirect
     */
    private function redirectToIndex(string $message): Redirect
    {
        $this->messageManager->addErrorMessage($message);
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('magebit_mcp/auditlog/index');
        return $redirect;
    }
}
