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
 * Admin landing page for MCP connection (bearer token) management. Pops a
 * one-shot plaintext bearer from the session as a warning message — not a
 * success message, which would imply the user can safely navigate away
 * before copying the secret.
 */
class Index extends Action implements HttpGetActionInterface
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
        $this->surfaceNewBearerIfAny();

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_tokens');
        $resultPage->getConfig()->getTitle()->prepend((string) __('MCP Connections'));
        return $resultPage;
    }

    private function surfaceNewBearerIfAny(): void
    {
        $bag = $this->_session->getData(Save::SESSION_KEY_PLAINTEXT, true);
        if (!is_array($bag)) {
            return;
        }
        $plaintext = $bag['plaintext'] ?? null;
        $tokenId = $bag['token_id'] ?? null;
        if (!is_string($plaintext) || $plaintext === '') {
            return;
        }
        $id = is_scalar($tokenId) ? (int) $tokenId : 0;

        $this->messageManager->addWarningMessage(
            (string) __(
                'Bearer token for connection #%1 — copy it now, this is the only time it will be shown: %2',
                $id,
                $plaintext
            )
        );
    }
}
