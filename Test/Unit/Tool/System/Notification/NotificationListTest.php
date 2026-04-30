<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Notification;

use ArrayIterator;
use Magebit\Mcp\Tool\System\Notification\NotificationList;
use Magento\AdminNotification\Model\Inbox;
use Magento\AdminNotification\Model\ResourceModel\Inbox\Collection;
use Magento\AdminNotification\Model\ResourceModel\Inbox\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationListTest extends TestCase
{
    /**
     * @phpstan-var CollectionFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CollectionFactory&MockObject $collectionFactory;

    /**
     * @phpstan-var Collection&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Collection&MockObject $collection;

    private NotificationList $tool;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);
        $this->tool = new NotificationList($this->collectionFactory);
    }

    public function testHappyPathReturnsUnreadOnlyByDefault(): void
    {
        $this->collection->expects(self::once())->method('addRemoveFilter');
        $this->collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('is_read', ['eq' => 0]);
        $this->collection->expects(self::once())
            ->method('setOrder')
            ->with('date_added', 'DESC');
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getItems')->willReturn([
            $this->stubItem(7, 1, 'Critical thing', 'desc', 'https://example/x', '2026-04-25 10:00:00', false),
        ]);

        $result = $this->tool->execute([]);
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $payload = json_decode($text, true);
        self::assertIsArray($payload);

        $rows = $payload['notifications'] ?? null;
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $first = $rows[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('critical', $first['severity_label'] ?? null);
        self::assertSame(7, $first['id'] ?? null);
        self::assertSame(false, $first['is_read'] ?? null);

        self::assertSame(1, $payload['total_count'] ?? null);
        self::assertSame(1, $payload['returned_count'] ?? null);
    }

    public function testIncludeReadSkipsUnreadFilter(): void
    {
        $this->collection->expects(self::once())->method('addRemoveFilter');
        $this->collection->expects(self::never())->method('addFieldToFilter');
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->method('getItems')->willReturn([]);

        $this->tool->execute(['include_read' => true]);
    }

    public function testSeverityFilterPassesIntegerArray(): void
    {
        $captured = null;
        $this->collection->method('addRemoveFilter');
        $this->collection->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $cond) use (&$captured) {
                if ($field === 'severity') {
                    $captured = $cond;
                }
                return $this->collection;
            });
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->method('getItems')->willReturn([]);

        $this->tool->execute(['severity' => [1, 2], 'include_read' => true]);

        self::assertSame(['in' => [1, 2]], $captured);
    }

    public function testInvalidSeverityRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['severity' => [9]]);
    }

    public function testLimitClampedToMax(): void
    {
        $captured = null;
        $this->collection->method('addRemoveFilter');
        $this->collection->method('addFieldToFilter');
        $this->collection->method('setPageSize')
            ->willReturnCallback(function ($size) use (&$captured) {
                $captured = $size;
                return $this->collection;
            });
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->method('getItems')->willReturn([]);

        $this->tool->execute(['limit' => 10000]);

        self::assertSame(200, $captured);
    }

    private function stubItem(
        int $id,
        int $severity,
        string $title,
        string $description,
        string $url,
        string $dateAdded,
        bool $isRead
    ): Inbox {
        $item = $this->createMock(Inbox::class);
        $item->method('getId')->willReturn($id);
        $item->method('getData')->willReturnMap([
            ['severity', null, $severity],
            ['title', null, $title],
            ['description', null, $description],
            ['url', null, $url],
            ['date_added', null, $dateAdded],
            ['is_read', null, $isRead ? 1 : 0],
        ]);
        return $item;
    }
}
