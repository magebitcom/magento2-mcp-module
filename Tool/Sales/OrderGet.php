<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\Sales;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * MCP tool: fetch summary data for a single order by increment ID.
 *
 * Read-only. Gated by the Magebit_Mcp::tool.sales.order.get ACL resource,
 * independent of the admin UI's Magento_Sales::sales_order resource — an admin
 * can be permitted to view orders in the UI but denied MCP access, or vice versa.
 */
class OrderGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.get';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_sales_order_get';

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Return summary data for a single sales order by its increment ID'
            . ' (e.g. "000000001"): status, grand total, currency, customer email'
            . ' and name, created-at timestamp, and visible line items.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'increment_id' => [
                    'type' => 'string',
                    'description' => 'Order increment ID as shown in the admin grid (e.g. "000000001").',
                    'minLength' => 1,
                    'maxLength' => 64,
                ],
            ],
            'required' => ['increment_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $incrementId = $arguments['increment_id'] ?? null;
        if (!is_string($incrementId) || $incrementId === '') {
            throw new LocalizedException(__('Argument "increment_id" is required and must be a non-empty string.'));
        }

        $order = $this->findByIncrementId($incrementId);
        $visibleItems = $this->visibleItems($order);

        $payload = [
            'increment_id' => $order->getIncrementId(),
            'entity_id' => (int) $order->getEntityId(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'grand_total' => (float) $order->getGrandTotal(),
            'currency_code' => $order->getOrderCurrencyCode(),
            'customer_email' => $order->getCustomerEmail(),
            'customer_name' => trim(sprintf(
                '%s %s',
                (string) $order->getCustomerFirstname(),
                (string) $order->getCustomerLastname()
            )),
            'created_at' => $order->getCreatedAt(),
            'items' => array_map(
                static fn(OrderItemInterface $item): array => [
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'qty_ordered' => (float) $item->getQtyOrdered(),
                    'price' => (float) $item->getPrice(),
                    'row_total' => (float) $item->getRowTotal(),
                ],
                $visibleItems
            ),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode order payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'item_count' => count($visibleItems),
            ]
        );
    }

    /**
     * Locate a single order by its increment ID.
     *
     * @param string $incrementId
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    private function findByIncrementId(string $incrementId): OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($items);
        if (!$order instanceof OrderInterface) {
            throw NoSuchEntityException::singleField('increment_id', $incrementId);
        }
        return $order;
    }

    /**
     * Keep only the parent order lines, dropping bundle / configurable children.
     *
     * @param OrderInterface $order
     * @return OrderItemInterface[]
     */
    private function visibleItems(OrderInterface $order): array
    {
        return array_values(array_filter(
            $order->getItems(),
            static fn(OrderItemInterface $item): bool => $item->getParentItem() === null
        ));
    }
}
