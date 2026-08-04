<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Cron;

use Magebit\Mcp\Model\Cron\ScheduleReader;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Cron\Status;
use Magento\Cron\Model\ConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    private const NOW = '2026-08-04 12:00:00';

    /**
     * @phpstan-var ConfigInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ConfigInterface&MockObject $cronConfig;

    /**
     * @phpstan-var ScheduleReader&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ScheduleReader&MockObject $scheduleReader;

    private Status $tool;

    protected function setUp(): void
    {
        $this->cronConfig = $this->createMock(ConfigInterface::class);
        $this->scheduleReader = $this->createMock(ScheduleReader::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($this->ts(self::NOW));

        $this->tool = new Status($this->cronConfig, $this->scheduleReader, $dateTime);
    }

    public function testMetadataMatchesConventions(): void
    {
        self::assertSame('system.cron.status', $this->tool->getName());
        self::assertSame('Magebit_Mcp::tool_system_cron_status', $this->tool->getAclResource());
        self::assertSame(WriteMode::READ, $this->tool->getWriteMode());
        self::assertFalse($this->tool->getConfirmationRequired());
        self::assertSame('Cron Status', $this->tool->getTitle());

        $schema = $this->tool->getInputSchema();
        self::assertIsArray($schema['properties']);
        self::assertSame(
            ['group', 'job_code', 'stuck_threshold_minutes', 'history_limit'],
            array_keys($schema['properties'])
        );
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testReportsPerJobHealthAcrossGroups(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute([]);

        self::assertTrue($payload['alive']);
        self::assertSame('2026-08-04T12:00:00+00:00', $payload['checked_at']);
        self::assertSame(60, $payload['stuck_threshold_minutes']);

        $jobs = $this->jobsByCode($payload);
        self::assertSame([
            'job_code' => 'catalog_product_alert',
            'group' => 'default',
            'configured' => true,
            'schedule' => '0 0 * * *',
            'last_success_at' => '2026-08-04T11:30:09+00:00',
            'last_error_at' => null,
            'last_error_message' => null,
            'pending' => 0,
            'running' => 0,
            'missed' => 0,
            'error' => 0,
            'stuck' => false,
            'never_scheduled' => false,
            'recent' => [],
        ], $jobs['catalog_product_alert']);

        self::assertSame(2, $jobs['sales_clean_quotes']['pending']);
        self::assertSame(1, $jobs['sales_clean_quotes']['error']);
        self::assertSame('2026-08-04T09:00:05+00:00', $jobs['sales_clean_quotes']['last_error_at']);
        self::assertSame('Segmentation fault', $jobs['sales_clean_quotes']['last_error_message']);
        self::assertNull($jobs['sales_clean_quotes']['last_success_at']);

        self::assertSame(4, $jobs['orphan_job']['missed']);
        self::assertFalse($jobs['orphan_job']['configured']);
        self::assertSame('unknown', $jobs['orphan_job']['group']);

        self::assertSame(
            ['failing' => 1, 'stuck' => 1, 'never_scheduled' => 1],
            $payload['summary']
        );
    }

    public function testProblemJobsAreListedFirst(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute([]);
        $jobs = $payload['jobs'];
        self::assertIsArray($jobs);

        $codes = [];
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            $codes[] = $job['job_code'] ?? null;
        }

        self::assertSame([
            'indexer_reindex_all_invalid',
            'sales_clean_quotes',
            'unused_job',
            'catalog_product_alert',
            'orphan_job',
        ], $codes);
    }

    public function testStuckIsTrueWhenRunningRowIsOlderThanThreshold(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $jobs = $this->jobsByCode($this->execute([]));

        self::assertTrue($jobs['indexer_reindex_all_invalid']['stuck']);
        self::assertSame(1, $jobs['indexer_reindex_all_invalid']['running']);
    }

    public function testStuckIsFalseWhenRunningRowIsWithinThreshold(): void
    {
        $this->stubConfig();
        $this->stubTotals([
            $this->totalRow('indexer_reindex_all_invalid', 'running', 1, [
                'oldest_executed_at' => '2026-08-04 11:55:00',
                'last_executed_at' => '2026-08-04 11:55:00',
            ]),
        ]);
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([]);
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $jobs = $this->jobsByCode($this->execute([]));

        self::assertFalse($jobs['indexer_reindex_all_invalid']['stuck']);
        self::assertSame(0, $this->summary($this->execute([]))['stuck']);
    }

    public function testAliveIsFalseWhenNothingSucceededWithinThreshold(): void
    {
        $this->stubConfig();
        $this->stubTotals([
            $this->totalRow('catalog_product_alert', 'success', 3, [
                'last_scheduled_at' => '2026-08-04 09:00:00',
                'last_finished_at' => '2026-08-04 09:00:09',
            ]),
        ]);
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([]);
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute([]);

        self::assertFalse($payload['alive']);
    }

    public function testNeverScheduledListsConfiguredJobsAbsentFromTheTable(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $jobs = $this->jobsByCode($this->execute([]));

        self::assertTrue($jobs['unused_job']['never_scheduled']);
        self::assertNull($jobs['unused_job']['last_success_at']);
        self::assertFalse($jobs['catalog_product_alert']['never_scheduled']);
    }

    public function testGroupsCarryPerGroupAliveAndCounts(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute([]);
        $groups = $payload['groups'];
        self::assertIsArray($groups);
        self::assertSame(['default', 'index', 'unknown'], array_keys($groups));

        self::assertSame([
            'jobs' => 3,
            'pending' => 2,
            'running' => 0,
            'missed' => 0,
            'error' => 1,
            'stuck' => 0,
            'failing' => 1,
            'never_scheduled' => 1,
            'last_success_at' => '2026-08-04T11:30:09+00:00',
            'alive' => true,
        ], $groups['default']);

        self::assertIsArray($groups['index']);
        self::assertFalse($groups['index']['alive']);
        self::assertSame(1, $groups['index']['stuck']);
    }

    public function testGroupFilterNarrowsTheReportButKeepsAliveGlobal(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute(['group' => 'index']);

        self::assertTrue($payload['alive'], 'Liveness must be measured over every job, not the filtered slice.');
        self::assertIsArray($payload['groups']);
        self::assertSame(['index'], array_keys($payload['groups']));
        self::assertSame(['indexer_reindex_all_invalid'], array_keys($this->jobsByCode($payload)));
    }

    public function testUnknownGroupRaisesLocalizedException(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown cron group "nope"');

        $this->tool->execute(['group' => 'nope']);
    }

    public function testGroupErrorListsEveryGroupEvenWhenJobCodeAlreadyNarrowed(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Known groups: default, index, unknown.');

        $this->tool->execute(['group' => 'index', 'job_code' => 'sales_clean_quotes']);
    }

    public function testJobCodeFilterReturnsASingleJob(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute(['job_code' => 'sales_clean_quotes']);

        self::assertSame(['sales_clean_quotes'], array_keys($this->jobsByCode($payload)));
        self::assertSame(['failing' => 1, 'stuck' => 0, 'never_scheduled' => 0], $payload['summary']);
    }

    public function testUnknownJobCodeRaisesLocalizedException(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown cron job "nope"');

        $this->tool->execute(['job_code' => 'nope']);
    }

    public function testNonScalarArgumentRaisesLocalizedException(): void
    {
        $this->stubConfig();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Argument "group" must be a string.');

        $this->tool->execute(['group' => ['index']]);
    }

    public function testLastErrorMessageIsTruncatedToFiveHundredCharacters(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([
            [
                'job_code' => 'sales_clean_quotes',
                'scheduled_at' => '2026-08-04 09:00:00',
                'finished_at' => '2026-08-04 09:00:05',
                'messages' => str_repeat('x', 900),
            ],
        ]);
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $jobs = $this->jobsByCode($this->execute([]));
        $message = $jobs['sales_clean_quotes']['last_error_message'];

        self::assertIsString($message);
        self::assertSame(500, strlen($message));
    }

    public function testOnlyTheNewestErrorRowFeedsLastErrorMessage(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([
            [
                'job_code' => 'sales_clean_quotes',
                'scheduled_at' => '2026-08-04 09:00:00',
                'finished_at' => '2026-08-04 09:00:05',
                'messages' => 'newest',
            ],
            [
                'job_code' => 'sales_clean_quotes',
                'scheduled_at' => '2026-08-03 09:00:00',
                'finished_at' => '2026-08-03 09:00:05',
                'messages' => 'older',
            ],
        ]);
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $jobs = $this->jobsByCode($this->execute([]));

        self::assertSame('newest', $jobs['sales_clean_quotes']['last_error_message']);
    }

    public function testRecentRunsAreAttachedPerJobAndBounded(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();

        $requestedCodes = null;
        $requestedLimit = null;
        $this->scheduleReader->method('fetchRecentRuns')->willReturnCallback(
            function (array $codes, string $since, int $limit) use (&$requestedCodes, &$requestedLimit): array {
                $requestedCodes = $codes;
                $requestedLimit = $limit;
                self::assertSame('2026-07-05 12:00:00', $since);
                return [
                    [
                        'job_code' => 'catalog_product_alert',
                        'status' => 'success',
                        'scheduled_at' => '2026-08-04 11:30:00',
                        'executed_at' => '2026-08-04 11:30:01',
                        'finished_at' => '2026-08-04 11:30:09',
                    ],
                    [
                        'job_code' => 'catalog_product_alert',
                        'status' => 'success',
                        'scheduled_at' => '2026-08-04 11:00:00',
                        'executed_at' => null,
                        'finished_at' => null,
                    ],
                ];
            }
        );

        $jobs = $this->jobsByCode($this->execute(['history_limit' => 1]));

        self::assertSame(
            [
                'catalog_product_alert',
                'indexer_reindex_all_invalid',
                'orphan_job',
                'sales_clean_quotes',
                'unused_job',
            ],
            is_array($requestedCodes) ? $requestedCodes : []
        );
        self::assertSame(5, $requestedLimit, 'Fetch cap must scale with the reported job count.');
        self::assertSame([[
            'status' => 'success',
            'scheduled_at' => '2026-08-04T11:30:00+00:00',
            'executed_at' => '2026-08-04T11:30:01+00:00',
            'finished_at' => '2026-08-04T11:30:09+00:00',
        ]], $jobs['catalog_product_alert']['recent']);
        self::assertSame([], $jobs['sales_clean_quotes']['recent']);
    }

    public function testHistoryLimitZeroSkipsTheDetailQuery(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->expects(self::never())->method('fetchRecentRuns');

        $jobs = $this->jobsByCode($this->execute(['history_limit' => 0]));

        self::assertSame([], $jobs['catalog_product_alert']['recent']);
    }

    public function testThresholdIsClampedToTheSchemaBounds(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        self::assertSame(1440, $this->execute(['stuck_threshold_minutes' => 99999])['stuck_threshold_minutes']);
        self::assertSame(1, $this->execute(['stuck_threshold_minutes' => 0])['stuck_threshold_minutes']);
    }

    public function testAuditSummaryCarriesCountsOnlyAndNoErrorText(): void
    {
        $this->stubConfig();
        $this->stubTotals();
        $this->stubErrors();
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $summary = $this->tool->execute([])->getAuditSummary();

        self::assertSame([
            'alive' => true,
            'jobs' => 5,
            'failing' => 1,
            'stuck' => 1,
            'never_scheduled' => 1,
        ], $summary);
    }

    public function testEmptyCronConfigAndEmptyTableProduceAnEmptyReport(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([]);
        $this->scheduleReader->method('fetchStatusTotals')->willReturn([]);
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([]);
        $this->scheduleReader->method('fetchRecentRuns')->willReturn([]);

        $payload = $this->execute([]);

        self::assertFalse($payload['alive']);
        self::assertSame([], $payload['jobs']);
        self::assertSame(['failing' => 0, 'stuck' => 0, 'never_scheduled' => 0], $payload['summary']);
    }

    /**
     * @return void
     */
    private function stubConfig(): void
    {
        $this->cronConfig->method('getJobs')->willReturn([
            'default' => [
                'catalog_product_alert' => [
                    'name' => 'catalog_product_alert',
                    'instance' => 'Vendor\\Module\\Cron\\Alert',
                    'method' => 'execute',
                    'schedule' => '0 0 * * *',
                ],
                'sales_clean_quotes' => [
                    'name' => 'sales_clean_quotes',
                    'schedule' => '0 0 * * *',
                ],
                'unused_job' => [
                    'name' => 'unused_job',
                    'config_path' => 'some/config/path',
                ],
            ],
            'index' => [
                'indexer_reindex_all_invalid' => [
                    'name' => 'indexer_reindex_all_invalid',
                    'schedule' => '* * * * *',
                ],
            ],
        ]);
    }

    /**
     * @phpstan-param list<array<string, mixed>>|null $rows
     * @return void
     */
    private function stubTotals(?array $rows = null): void
    {
        $rows ??= [
            $this->totalRow('catalog_product_alert', 'success', 3, [
                'last_scheduled_at' => '2026-08-04 11:30:00',
                'last_executed_at' => '2026-08-04 11:30:01',
                'last_finished_at' => '2026-08-04 11:30:09',
            ]),
            $this->totalRow('sales_clean_quotes', 'error', 1, [
                'last_scheduled_at' => '2026-08-04 09:00:00',
                'last_executed_at' => '2026-08-04 09:00:01',
                'last_finished_at' => '2026-08-04 09:00:05',
            ]),
            $this->totalRow('sales_clean_quotes', 'pending', 2, [
                'last_scheduled_at' => '2026-08-04 12:10:00',
            ]),
            $this->totalRow('indexer_reindex_all_invalid', 'running', 1, [
                'last_scheduled_at' => '2026-08-04 09:00:00',
                'last_executed_at' => '2026-08-04 09:00:00',
                'oldest_executed_at' => '2026-08-04 09:00:00',
            ]),
            $this->totalRow('orphan_job', 'missed', 4, [
                'last_scheduled_at' => '2026-08-04 10:00:00',
            ]),
        ];

        $this->scheduleReader->method('fetchStatusTotals')
            ->with('2026-07-05 12:00:00')
            ->willReturn($rows);
    }

    /**
     * @return void
     */
    private function stubErrors(): void
    {
        $this->scheduleReader->method('fetchLatestErrors')->willReturn([
            [
                'job_code' => 'sales_clean_quotes',
                'scheduled_at' => '2026-08-04 09:00:00',
                'finished_at' => '2026-08-04 09:00:05',
                'messages' => 'Segmentation fault',
            ],
        ]);
    }

    /**
     * @param string $jobCode
     * @param string $status
     * @param int $runCount
     * @phpstan-param array<string, string|null> $overrides
     * @return array<string, mixed>
     */
    private function totalRow(string $jobCode, string $status, int $runCount, array $overrides = []): array
    {
        return array_merge([
            'job_code' => $jobCode,
            'status' => $status,
            'run_count' => $runCount,
            'last_scheduled_at' => null,
            'last_executed_at' => null,
            'last_finished_at' => null,
            'oldest_executed_at' => null,
        ], $overrides);
    }

    /**
     * @phpstan-param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(array $arguments): array
    {
        $content = $this->tool->execute($arguments)->getContent();
        self::assertCount(1, $content);
        self::assertSame('text', $content[0]['type']);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @phpstan-var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return array<string, array<string, mixed>>
     */
    private function jobsByCode(array $payload): array
    {
        $jobs = $payload['jobs'] ?? null;
        self::assertIsArray($jobs);
        $byCode = [];
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            $code = $job['job_code'] ?? null;
            self::assertIsString($code);
            /** @phpstan-var array<string, mixed> $job */
            $byCode[$code] = $job;
        }
        return $byCode;
    }

    /**
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return array<string, mixed>
     */
    private function summary(array $payload): array
    {
        $summary = $payload['summary'] ?? null;
        self::assertIsArray($summary);
        /** @phpstan-var array<string, mixed> $summary */
        return $summary;
    }

    /**
     * @param string $utc
     * @return int
     */
    private function ts(string $utc): int
    {
        $timestamp = strtotime($utc . ' UTC');
        self::assertIsInt($timestamp);
        return $timestamp;
    }
}
