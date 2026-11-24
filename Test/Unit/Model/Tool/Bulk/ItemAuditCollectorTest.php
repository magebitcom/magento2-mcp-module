<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\Tool\Bulk\ItemAuditCollector;
use PHPUnit\Framework\TestCase;

class ItemAuditCollectorTest extends TestCase
{
    /**
     * A tool without per-item audit never begins the collector, so the summary stays exactly as it was.
     *
     * @return void
     */
    public function testNothingIsAddedUntilBegun(): void
    {
        $collector = new ItemAuditCollector();
        $collector->add(0, 'a', 'ok', ['x' => 1]);

        self::assertSame([], $collector->toSummary());
    }

    /**
     * @return void
     */
    public function testEntriesKeepIndexKeyStatusAndDetail(): void
    {
        $collector = new ItemAuditCollector();
        $collector->begin();
        $collector->add(0, 'a/b/c', 'ok', ['previous' => '1', 'value' => '2']);
        $collector->add(2, null, 'error', ['value' => '3']);

        self::assertSame([
            'items' => [
                ['index' => 0, 'key' => 'a/b/c', 'status' => 'ok', 'detail' => ['previous' => '1', 'value' => '2']],
                ['index' => 2, 'key' => null, 'status' => 'error', 'detail' => ['value' => '3']],
            ],
        ], $collector->toSummary());
    }

    /**
     * @return void
     */
    public function testBegunWithoutEntriesStillReportsAnEmptyList(): void
    {
        $collector = new ItemAuditCollector();
        $collector->begin();

        self::assertSame(['items' => []], $collector->toSummary());
    }

    /**
     * The collector holds a bounded amount: once an entry does not fit, it and every later one are only counted.
     *
     * @return void
     */
    public function testOnlyLeadingEntriesWithinTheBoundAreKept(): void
    {
        $collector = new ItemAuditCollector(200);
        $collector->begin();
        for ($i = 0; $i < 10; $i++) {
            $collector->add($i, 'k' . $i, 'ok', ['value' => str_repeat('v', 30)]);
        }
        $collector->add(10, 'k10', 'ok', []);

        $summary = $collector->toSummary();
        self::assertIsArray($summary['items'] ?? null);
        $kept = count($summary['items']);
        self::assertGreaterThan(0, $kept);
        self::assertLessThan(10, $kept);
        self::assertSame(range(0, $kept - 1), array_column($summary['items'], 'index'));
        self::assertSame(11 - $kept, $summary['items_truncated'] ?? null);
        self::assertLessThanOrEqual(200, strlen((string) json_encode($summary['items'])));
    }

    /**
     * The default bound leaves headroom over the audit budget, since redaction can shrink entries before the logger
     * fits the summary.
     *
     * @return void
     */
    public function testDefaultBoundIsTwiceTheSummaryBudget(): void
    {
        self::assertSame(2 * AuditLogger::SUMMARY_BUDGET_BYTES, ItemAuditCollector::DEFAULT_MAX_BYTES);
    }

    /**
     * @return void
     */
    public function testUnencodableDetailIsCountedNotKept(): void
    {
        $collector = new ItemAuditCollector();
        $collector->begin();
        $collector->add(0, 'a', 'ok', ['value' => "\xB1\x31"]);
        $collector->add(1, 'b', 'ok', ['value' => 'fine']);

        self::assertSame([
            'items' => [['index' => 1, 'key' => 'b', 'status' => 'ok', 'detail' => ['value' => 'fine']]],
            'items_truncated' => 1,
        ], $collector->toSummary());
    }
}
