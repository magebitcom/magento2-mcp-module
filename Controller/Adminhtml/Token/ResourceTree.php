<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * AJAX endpoint backing the MCP token "Resource Access" tree. Re-emits the
 * jstree payload for a given admin user, so the picker can re-render every
 * time the operator picks a different admin from the form's dropdown.
 */
class ResourceTree extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param ToolResourceTree $toolResourceTree
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ToolResourceTree $toolResourceTree
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $rawId = $this->getRequest()->getParam('admin_user_id');
        $adminUserId = is_scalar($rawId) ? (int) $rawId : 0;

        $tree = $this->toolResourceTree->build($adminUserId > 0 ? $adminUserId : null);

        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->jsonFactory->create();
        $result->setData(['tree' => $tree]);
        return $result;
    }
}
