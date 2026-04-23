<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin landing page for MCP connection (bearer token) management.
 *
 * Pops a one-shot plaintext bearer from the session if the user just came
 * from {@see Save} — shown as a warning message (warning rather than success
 * because the user MUST copy it before navigating away; success messages
 * read as "you're done, move on").
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Page
    {
        $this->surfaceNewBearerIfAny();

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_tokens');
        $resultPage->getConfig()->getTitle()->prepend((string) __('MCP Connections'));
        return $resultPage;
    }

    /**
     * Pull the one-shot plaintext from the session and surface it as a warning.
     *
     * @return void
     */
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
