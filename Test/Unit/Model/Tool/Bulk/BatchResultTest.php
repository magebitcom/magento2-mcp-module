<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use PHPUnit\Framework\TestCase;

class BatchResultTest extends TestCase
{
    public function testSummaryCounts(): void
    {
        $batch = new BatchResult();
        $batch->add(0, 'A', ItemResult::ok(1));
        $batch->add(1, 'B', ItemResult::error(BulkErrorCode::ITEM_FAILED, 'boom'));
        $batch->add(2, 'C', ItemResult::skipped(BulkErrorCode::CANCELLED));

        self::assertSame(['total' => 3, 'ok' => 1, 'failed' => 1, 'skipped' => 1], $batch->summary());
        self::assertFalse($batch->allFailed());
        self::assertCount(3, $batch->rows());
        self::assertSame('B', $batch->rows()[1]['key']);
    }

    public function testAllFailed(): void
    {
        $batch = new BatchResult();
        self::assertFalse($batch->allFailed());
        $batch->add(0, 'A', ItemResult::error(BulkErrorCode::NOT_FOUND, 'x'));
        $batch->add(1, 'B', ItemResult::error(BulkErrorCode::CONFLICT, 'y'));
        self::assertTrue($batch->allFailed());
    }

    public function testEnvelopeOmitsNoticeUnlessSet(): void
    {
        $batch = new BatchResult();
        $batch->add(0, 'A', ItemResult::ok());
        $envelope = $batch->toEnvelope(true);
        self::assertSame(['dry_run', 'summary', 'results'], array_keys($envelope));
        self::assertTrue($envelope['dry_run']);

        $batch->setNotice('Partial run');
        $envelope = $batch->toEnvelope(false);
        self::assertSame(['dry_run', 'summary', 'results', 'notice'], array_keys($envelope));
        self::assertSame('Partial run', $envelope['notice'] ?? null);
        self::assertFalse($envelope['dry_run']);

        $batch->setNotice(null);
        self::assertArrayNotHasKey('notice', $batch->toEnvelope(false));
    }
}
