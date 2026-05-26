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
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the prompt edit form. The loaded model is registered under
 * `current_admin_prompt` so the form block can read it; null = new-mode.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_prompts';
    public const REGISTRY_KEY = 'current_admin_prompt';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param Registry $registry
     * @param AdminPromptRepository $repository
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly AdminPromptRepository $repository
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        $idRaw = $this->getRequest()->getParam('id');
        $id = is_scalar($idRaw) ? (int) $idRaw : 0;

        $prompt = null;
        if ($id > 0) {
            try {
                $prompt = $this->repository->getById($id);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(
                    (string) __('Prompt #%1 not found.', $id)
                );
                /** @var Redirect $redirect */
                $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                return $redirect->setPath('*/*/index');
            }
        }

        $this->registry->register(self::REGISTRY_KEY, $prompt, true);

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magebit_Mcp::mcp_prompts');
        $resultPage->addBreadcrumb((string) __('MCP'), (string) __('MCP'));
        $resultPage->addBreadcrumb(
            (string) __('Prompts'),
            (string) __('Prompts'),
            $this->getUrl('magebit_mcp/prompt/index')
        );

        if ($prompt instanceof AdminPrompt) {
            $resultPage->addBreadcrumb((string) __('Edit'), (string) __('Edit'));
            $resultPage->getConfig()->getTitle()->prepend(
                (string) __('Edit Prompt "%1"', $prompt->getTitle())
            );
        } else {
            $resultPage->addBreadcrumb((string) __('New'), (string) __('New'));
            $resultPage->getConfig()->getTitle()->prepend((string) __('New Prompt'));
        }

        return $resultPage;
    }
}
