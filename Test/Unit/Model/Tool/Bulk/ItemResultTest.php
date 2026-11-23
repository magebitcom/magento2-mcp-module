<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use PHPUnit\Framework\TestCase;

class ItemResultTest extends TestCase
{
    public function testOkRow(): void
    {
        self::assertSame(
            ['index' => 0, 'key' => 'SKU-1', 'status' => 'ok', 'error_code' => null, 'message' => null, 'id' => 12],
            ItemResult::ok(12)->toRow(0, 'SKU-1')
        );
    }

    public function testErrorRow(): void
    {
        $row = ItemResult::error(BulkErrorCode::NOT_FOUND, 'No such product')->toRow(2, 'X');
        self::assertSame('error', $row['status']);
        self::assertSame('NOT_FOUND', $row['error_code']);
        self::assertSame('No such product', $row['message']);
        self::assertNull($row['id']);
    }

    public function testSkippedRow(): void
    {
        $result = ItemResult::skipped(BulkErrorCode::CANCELLED);
        self::assertSame('skipped', $result->getStatus());
        self::assertSame('CANCELLED', $result->getErrorCode());
        self::assertSame('skipped', $result->toRow(1, null)['status']);
    }
}
