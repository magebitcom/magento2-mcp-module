<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Cron;

use Magebit\Mcp\Model\Cron\ScheduleReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScheduleReaderTest extends TestCase
{
    /**
     * @phpstan-var ResourceConnection&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ResourceConnection&MockObject $resourceConnection;

    /**
     * @phpstan-var AdapterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdapterInterface&MockObject $connection;

    /**
     * @phpstan-var Select&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Select&MockObject $select;

    /**
     * @phpstan-var list<array{0: string, 1: mixed}>
     */
    private array $whereCalls = [];

    /**
     * @phpstan-var array<string, mixed>
     */
    private array $columns = [];

    private ScheduleReader $reader;

    protected function setUp(): void
    {
        $this->whereCalls = [];
        $this->columns = [];

        $this->select = $this->createMock(Select::class);
        $select = $this->select;

        $this->select->method('from')->willReturnCallback(
            function (mixed $table, mixed $columns = '*') use ($select): Select {
                if (is_array($columns)) {
                    /** @phpstan-var array<string, mixed> $columns */
                    $this->columns = $columns;
                }
                return $select;
            }
        );
        $this->select->method('where')->willReturnCallback(
            function (mixed $cond, mixed $value = null) use ($select): Select {
                $this->whereCalls[] = [is_string($cond) ? $cond : '', $value];
                return $select;
            }
        );
        $this->select->method('group')->willReturn($this->select);
        $this->select->method('order')->willReturn($this->select);
        $this->select->method('limit')->willReturn($this->select);

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (mixed $name): string => 'pfx_' . (is_string($name) ? $name : ''));

        $this->reader = new ScheduleReader($this->resourceConnection);
    }

    public function testStatusTotalsAggregateByJobAndStatus(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with($this->select)
            ->willReturn([
                [
                    'job_code' => 'sales_clean_quotes',
                    'status' => 'success',
                    'run_count' => '4',
                    'last_scheduled_at' => '2026-08-04 11:00:00',
                    'last_executed_at' => '2026-08-04 11:00:01',
                    'last_finished_at' => '2026-08-04 11:00:09',
                    'oldest_executed_at' => '2026-08-04 08:00:01',
                ],
                [
                    'job_code' => 'sales_clean_quotes',
                    'status' => 'pending',
                    'run_count' => '2',
                    'last_scheduled_at' => '2026-08-04 12:10:00',
                    'last_executed_at' => null,
                    'last_finished_at' => null,
                    'oldest_executed_at' => null,
                ],
            ]);

        $rows = $this->reader->fetchStatusTotals('2026-07-05 12:00:00');

        self::assertCount(2, $rows);
        self::assertSame([
            'job_code' => 'sales_clean_quotes',
            'status' => 'success',
            'run_count' => 4,
            'last_scheduled_at' => '2026-08-04 11:00:00',
            'last_executed_at' => '2026-08-04 11:00:01',
            'last_finished_at' => '2026-08-04 11:00:09',
            'oldest_executed_at' => '2026-08-04 08:00:01',
        ], $rows[0]);
        self::assertSame(2, $rows[1]['run_count']);
        self::assertNull($rows[1]['last_finished_at']);
    }

    public function testStatusTotalsBoundHistoryWindowAndDoNotFilterByJob(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->reader->fetchStatusTotals('2026-07-05 12:00:00');

        self::assertCount(1, $this->whereCalls, 'The aggregate must not add a job_code filter.');
        self::assertStringContainsString('scheduled_at', $this->whereCalls[0][0]);
        self::assertSame('2026-07-05 12:00:00', $this->whereCalls[0][1]);
        self::assertArrayHasKey('run_count', $this->columns);
        self::assertArrayHasKey('oldest_executed_at', $this->columns);
    }

    public function testStatusTotalsSkipRowsWithoutJobCode(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['status' => 'success', 'run_count' => '1'],
            'not-a-row',
            ['job_code' => 'ok', 'status' => 'success', 'run_count' => '1'],
        ]);

        $rows = $this->reader->fetchStatusTotals('2026-07-05 12:00:00');

        self::assertCount(1, $rows);
        self::assertSame('ok', $rows[0]['job_code']);
    }

    public function testRecentRunsAreBoundedAndFilteredByJobCode(): void
    {
        $this->select->expects(self::once())->method('limit')->with(10);
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'job_code' => 'sales_clean_quotes',
                    'status' => 'success',
                    'scheduled_at' => '2026-08-04 11:00:00',
                    'executed_at' => '2026-08-04 11:00:01',
                    'finished_at' => '2026-08-04 11:00:09',
                ],
            ]);

        $rows = $this->reader->fetchRecentRuns(['sales_clean_quotes', 'other'], '2026-07-05 12:00:00', 10);

        self::assertSame([[
            'job_code' => 'sales_clean_quotes',
            'status' => 'success',
            'scheduled_at' => '2026-08-04 11:00:00',
            'executed_at' => '2026-08-04 11:00:01',
            'finished_at' => '2026-08-04 11:00:09',
        ]], $rows);
        self::assertCount(2, $this->whereCalls);
        self::assertStringContainsString('job_code', $this->whereCalls[1][0]);
        self::assertSame(['sales_clean_quotes', 'other'], $this->whereCalls[1][1]);
    }

    public function testRecentRunsShortCircuitWithoutJobCodesOrLimit(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->reader->fetchRecentRuns([], '2026-07-05 12:00:00', 10));
        self::assertSame([], $this->reader->fetchRecentRuns(['a'], '2026-07-05 12:00:00', 0));
    }

    public function testLatestErrorsRestrictToErrorStatusAndCarryMessages(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'job_code' => 'sales_clean_quotes',
                    'scheduled_at' => '2026-08-04 09:00:00',
                    'finished_at' => '2026-08-04 09:00:05',
                    'messages' => 'boom',
                ],
            ]);

        $rows = $this->reader->fetchLatestErrors(['sales_clean_quotes'], '2026-07-05 12:00:00', 5);

        self::assertSame([[
            'job_code' => 'sales_clean_quotes',
            'scheduled_at' => '2026-08-04 09:00:00',
            'finished_at' => '2026-08-04 09:00:05',
            'messages' => 'boom',
        ]], $rows);

        $conditions = array_column($this->whereCalls, 0);
        self::assertCount(3, $conditions);
        self::assertStringContainsString('status', $conditions[2]);
        self::assertSame('error', $this->whereCalls[2][1]);
    }

    public function testLatestErrorsShortCircuitWithoutJobCodes(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->reader->fetchLatestErrors([], '2026-07-05 12:00:00', 5));
    }
}
