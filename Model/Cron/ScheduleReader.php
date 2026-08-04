<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Cron;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * Read-only `cron_schedule` access for the cron diagnostics tool. Every query is
 * bounded by a time window. The aggregate has no `job_code` predicate, so it is
 * an index-ordered group scan over (job_code, status, scheduled_at) — no sort,
 * but not a range seek; the two detail queries do seek on the leading column.
 */
class ScheduleReader
{
    public const TABLE = 'cron_schedule';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * One aggregate pass over the window: run counts and boundary timestamps per
     * (job_code, status). Deliberately unfiltered by job so liveness stays global.
     *
     * @param string $sinceUtc Window start as `Y-m-d H:i:s` in UTC.
     * @phpstan-return list<array{
     *     job_code: string,
     *     status: string,
     *     run_count: int,
     *     last_scheduled_at: string|null,
     *     last_executed_at: string|null,
     *     last_finished_at: string|null,
     *     oldest_executed_at: string|null
     * }>
     * @return array[]
     */
    public function fetchStatusTotals(string $sinceUtc): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->tableName(), [
                'job_code' => 'job_code',
                'status' => 'status',
                'run_count' => new Expression('COUNT(*)'),
                'last_scheduled_at' => new Expression('MAX(scheduled_at)'),
                'last_executed_at' => new Expression('MAX(executed_at)'),
                'last_finished_at' => new Expression('MAX(finished_at)'),
                'oldest_executed_at' => new Expression('MIN(executed_at)'),
            ])
            ->where('(scheduled_at IS NULL OR scheduled_at >= ?)', $sinceUtc)
            ->group(['job_code', 'status']);

        $rows = [];
        foreach ($this->fetch($select) as $row) {
            $jobCode = $this->stringOrNull($row, 'job_code');
            if ($jobCode === null) {
                continue;
            }
            $rows[] = [
                'job_code' => $jobCode,
                'status' => (string) $this->stringOrNull($row, 'status'),
                'run_count' => $this->intValue($row, 'run_count'),
                'last_scheduled_at' => $this->stringOrNull($row, 'last_scheduled_at'),
                'last_executed_at' => $this->stringOrNull($row, 'last_executed_at'),
                'last_finished_at' => $this->stringOrNull($row, 'last_finished_at'),
                'oldest_executed_at' => $this->stringOrNull($row, 'oldest_executed_at'),
            ];
        }

        return $rows;
    }

    /**
     * Newest-first run history for the given jobs, hard-capped by $limit rows in
     * total. `messages` is excluded — history rows are only used for the timeline.
     *
     * @phpstan-param list<string> $jobCodes
     * @param array $jobCodes
     * @param string $sinceUtc
     * @param int $limit
     * @phpstan-return list<array{
     *     job_code: string,
     *     status: string,
     *     scheduled_at: string|null,
     *     executed_at: string|null,
     *     finished_at: string|null
     * }>
     * @return array[]
     */
    public function fetchRecentRuns(array $jobCodes, string $sinceUtc, int $limit): array
    {
        if ($jobCodes === [] || $limit < 1) {
            return [];
        }

        $select = $this->newestFirstSelect($jobCodes, $sinceUtc, $limit, [
            'job_code' => 'job_code',
            'status' => 'status',
            'scheduled_at' => 'scheduled_at',
            'executed_at' => 'executed_at',
            'finished_at' => 'finished_at',
        ]);

        $rows = [];
        foreach ($this->fetch($select) as $row) {
            $jobCode = $this->stringOrNull($row, 'job_code');
            if ($jobCode === null) {
                continue;
            }
            $rows[] = [
                'job_code' => $jobCode,
                'status' => (string) $this->stringOrNull($row, 'status'),
                'scheduled_at' => $this->stringOrNull($row, 'scheduled_at'),
                'executed_at' => $this->stringOrNull($row, 'executed_at'),
                'finished_at' => $this->stringOrNull($row, 'finished_at'),
            ];
        }

        return $rows;
    }

    /**
     * Newest-first failed rows for the given jobs, with their `messages` payload.
     *
     * @phpstan-param list<string> $jobCodes
     * @param array $jobCodes
     * @param string $sinceUtc
     * @param int $limit
     * @phpstan-return list<array{
     *     job_code: string,
     *     scheduled_at: string|null,
     *     finished_at: string|null,
     *     messages: string|null
     * }>
     * @return array[]
     */
    public function fetchLatestErrors(array $jobCodes, string $sinceUtc, int $limit): array
    {
        if ($jobCodes === [] || $limit < 1) {
            return [];
        }

        $select = $this->newestFirstSelect($jobCodes, $sinceUtc, $limit, [
            'job_code' => 'job_code',
            'scheduled_at' => 'scheduled_at',
            'finished_at' => 'finished_at',
            'messages' => 'messages',
        ])->where('status = ?', Schedule::STATUS_ERROR);

        $rows = [];
        foreach ($this->fetch($select) as $row) {
            $jobCode = $this->stringOrNull($row, 'job_code');
            if ($jobCode === null) {
                continue;
            }
            $rows[] = [
                'job_code' => $jobCode,
                'scheduled_at' => $this->stringOrNull($row, 'scheduled_at'),
                'finished_at' => $this->stringOrNull($row, 'finished_at'),
                'messages' => $this->stringOrNull($row, 'messages'),
            ];
        }

        return $rows;
    }

    /**
     * @phpstan-param list<string> $jobCodes
     * @param array $jobCodes
     * @param string $sinceUtc
     * @param int $limit
     * @phpstan-param array<string, string> $columns
     * @param array $columns
     * @return Select
     */
    private function newestFirstSelect(array $jobCodes, string $sinceUtc, int $limit, array $columns): Select
    {
        return $this->resourceConnection->getConnection()->select()
            ->from($this->tableName(), $columns)
            ->where('(scheduled_at IS NULL OR scheduled_at >= ?)', $sinceUtc)
            ->where('job_code IN (?)', $jobCodes)
            ->order(['scheduled_at DESC', 'schedule_id DESC'])
            ->limit($limit);
    }

    /**
     * @param Select $select
     * @phpstan-return list<array<string, mixed>>
     * @return array[]
     */
    private function fetch(Select $select): array
    {
        $rows = [];
        foreach ($this->resourceConnection->getConnection()->fetchAll($select) as $row) {
            if (is_array($row)) {
                /** @phpstan-var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return string
     */
    private function tableName(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }

    /**
     * @phpstan-param array<string, mixed> $row
     * @param array $row
     * @param string $key
     * @return string|null
     */
    private function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @phpstan-param array<string, mixed> $row
     * @param array $row
     * @param string $key
     * @return int
     */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (int) $value : 0;
    }
}
