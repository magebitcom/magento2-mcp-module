<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Notification;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\AdminNotification\Model\Inbox;
use Magento\AdminNotification\Model\ResourceModel\Inbox\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\MessageInterface;

/**
 * MCP tool `system.notification.list` — read-only listing of admin
 * inbox notifications. Mirrors the admin notifications panel.
 */
class NotificationList implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.notification.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_notification_list';

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    private const SEVERITY_LABELS = [
        MessageInterface::SEVERITY_CRITICAL => 'critical',
        MessageInterface::SEVERITY_MAJOR => 'major',
        MessageInterface::SEVERITY_MINOR => 'minor',
        MessageInterface::SEVERITY_NOTICE => 'notice',
    ];

    /**
     * @param CollectionFactory $inboxCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $inboxCollectionFactory
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
        return 'List Admin Notifications';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List admin inbox notifications, newest first. Defaults '
            . 'to unread items only — pass `include_read: true` for the '
            . 'full set. Soft-removed items are always excluded. '
            . 'Optionally filter by `severity` (`1=critical`, `2=major`, '
            . '`3=minor`, `4=notice`).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('severity', fn (ArrayBuilder $a) => $a
                ->ofIntegers(fn (IntegerBuilder $i) => $i
                    ->minimum(MessageInterface::SEVERITY_CRITICAL)
                    ->maximum(MessageInterface::SEVERITY_NOTICE)
                )
                ->minItems(1)
                ->description('Severity ids to include (1=critical, '
                    . '2=major, 3=minor, 4=notice).')
            )
            ->boolean('include_read', fn (BooleanBuilder $b) => $b
                ->description('Include already-read notifications. '
                    . 'Defaults to `false`.')
            )
            ->integer('limit', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(self::MAX_LIMIT)
                ->description('Maximum rows to return. Default 50, max 200.')
            )
            ->toArray();
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
    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_AdminNotification::show_list';
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
        $includeRead = (bool) ($arguments['include_read'] ?? false);
        $limit = $this->resolveLimit($arguments['limit'] ?? null);
        $severityFilter = $this->resolveSeverityFilter($arguments['severity'] ?? null);

        $collection = $this->inboxCollectionFactory->create();
        $collection->addRemoveFilter();
        if (!$includeRead) {
            $collection->addFieldToFilter('is_read', ['eq' => 0]);
        }
        if ($severityFilter !== []) {
            $collection->addFieldToFilter('severity', ['in' => $severityFilter]);
        }
        $collection->setOrder('date_added', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $rows = [];
        foreach ($collection->getItems() as $item) {
            if (!$item instanceof Inbox) {
                continue;
            }
            $severity = $this->scalarToInt($item->getData('severity'));
            $idValue = $item->getId();
            $rows[] = [
                'id' => is_scalar($idValue) ? (int) $idValue : 0,
                'severity' => $severity,
                'severity_label' => self::SEVERITY_LABELS[$severity] ?? 'unknown',
                'title' => $this->scalarToString($item->getData('title')),
                'description' => $this->scalarToString($item->getData('description')),
                'url' => $this->stringOrNull($item->getData('url')),
                'date_added' => $this->scalarToString($item->getData('date_added')),
                'is_read' => $this->scalarToInt($item->getData('is_read')) === 1,
            ];
        }

        $totalCount = (int) $collection->getSize();
        $payload = [
            'notifications' => $rows,
            'total_count' => $totalCount,
            'returned_count' => count($rows),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode notification list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'returned_count' => count($rows),
                'total_count' => $totalCount,
                'include_read' => $includeRead,
            ]
        );
    }

    /**
     * @param mixed $raw
     * @return int
     */
    private function resolveLimit(mixed $raw): int
    {
        if ($raw === null) {
            return self::DEFAULT_LIMIT;
        }
        $limit = $this->scalarToInt($raw);
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param mixed $raw
     * @return int[]
     * @throws LocalizedException
     */
    private function resolveSeverityFilter(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new LocalizedException(
                __('Filter "severity" must be an array of integers (1-4).')
            );
        }
        $severities = [];
        foreach ($raw as $entry) {
            if (!is_scalar($entry)) {
                throw new LocalizedException(
                    __('Severity values must be one of 1, 2, 3, 4.')
                );
            }
            $value = (int) $entry;
            if (!isset(self::SEVERITY_LABELS[$value])) {
                throw new LocalizedException(
                    __('Severity values must be one of 1, 2, 3, 4.')
                );
            }
            $severities[$value] = true;
        }
        return array_keys($severities);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function scalarToInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
