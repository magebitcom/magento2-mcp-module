<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Tools;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * Enable/disable a single MCP tool from the Tools management page.
 */
class Toggle extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tool_management';

    /**
     * @param Context $context
     * @param ToolRegistryInterface $toolRegistry
     * @param DisabledTools $disabledTools
     */
    public function __construct(
        Context $context,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly DisabledTools $disabledTools
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('magebit_mcp/tools/index');

        $tool = $this->getRequest()->getParam('tool');
        $disabled = $this->getRequest()->getParam('disabled') === '1';
        if (!is_string($tool) || !$this->toolRegistry->has($tool)) {
            $this->messageManager->addErrorMessage((string) __('Unknown tool.'));
            return $redirect;
        }

        $this->disabledTools->setDisabled($tool, $disabled);
        $this->messageManager->addSuccessMessage((string) __(
            $disabled ? 'Tool "%1" disabled.' : 'Tool "%1" enabled.',
            $tool
        ));
        return $redirect;
    }
}
