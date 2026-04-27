<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the "New MCP Connection" form. Tokens are mint-once (rotate-not-edit),
 * so there's no matching Edit controller — the form always posts to {@see Save}.
 */
class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_tokens');
        $resultPage->addBreadcrumb((string) __('MCP'), (string) __('MCP'));
        $resultPage->addBreadcrumb(
            (string) __('Connections'),
            (string) __('Connections'),
            $this->getUrl('magebit_mcp/token/index')
        );
        $resultPage->addBreadcrumb((string) __('New'), (string) __('New'));
        $resultPage->getConfig()->getTitle()->prepend((string) __('New MCP Connection'));
        return $resultPage;
    }
}
