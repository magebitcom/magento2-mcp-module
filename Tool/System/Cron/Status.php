<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Cron;

use DateTimeImmutable;
use DateTimeZone;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Cron\ScheduleReader;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Cron\Model\ConfigInterface;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Observer\ProcessCronQueueObserver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use stdClass;

/**
 * MCP tool `system.cron.status` — answers "is cron alive?" and "why didn't job X
 * run?" from `cron_schedule` plus the configured job list.
 */
class Status implements ToolInterface
{
    public const TOOL_NAME = 'system.cron.status';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_cron_status';

    /** Bucket for schedule rows whose job is no longer in any crontab group. */
    public const UNKNOWN_GROUP = 'unknown';

    private const DB_DATE_FORMAT = 'Y-m-d H:i:s';

    private const DEFAULT_THRESHOLD_MINUTES = 60;
    private const MIN_THRESHOLD_MINUTES = 1;
    private const MAX_THRESHOLD_MINUTES = 1440;

    private const DEFAULT_HISTORY_LIMIT = 5;
    private const MAX_HISTORY_LIMIT = 50;

    /**
     * Upper bound on how far back any query reaches, in days. This is a cost
     * bound only — Magento's own history cleanup prunes far more aggressively,
     * so the real limit on what can be reported is the per-group retention.
     */
    private const QUERY_WINDOW_DAYS = 30;

    private const CONFIG_PATH_TEMPLATE = 'system/cron/%s/%s';

    /** Hard ceiling on detail rows pulled for the per-job timeline. */
    private const MAX_HISTORY_ROWS = 1000;

    /** Error rows pulled per failing job to find its newest message. */
    private const ERROR_ROWS_PER_JOB = 3;

    private const MAX_ERROR_ROWS = 150;

    private const MAX_ERROR_MESSAGE_LENGTH = 500;

    /**
     * @param ConfigInterface $cronConfig
     * @param ScheduleReader $scheduleReader
     * @param ScopeConfigInterface $scopeConfig
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ConfigInterface $cronConfig,
        private readonly ScheduleReader $scheduleReader,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime
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
        return 'Cron Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Diagnose scheduled jobs (cron). Call this whenever something '
            . 'that should happen automatically did not — prices or stock not '
            . 'updating, emails not sending, reports or currency rates stale, '
            . 'indexes never refreshing. Reports whether cron is running at all '
            . '(`alive`), and per job: when it last succeeded, when it last '
            . 'failed and why, how many runs are queued (`pending`) or in '
            . 'flight (`running`), and whether a run is hung (`stuck`). '
            . 'CRITICAL — Magento deletes its own schedule history very '
            . 'quickly: successful runs survive only `retention.success_minutes` '
            . 'per group (60 by default) and upcoming runs are created only '
            . '`retention.schedule_ahead_minutes` in advance (4-20 by default). '
            . 'So `no_run_history: true` and `last_success_at: null` mean "no '
            . 'rows survive for this job right now" — they are NOT evidence '
            . 'that the job never ran or that its schedule is missing. For any '
            . 'job that runs less often than that retention window (hourly, '
            . 'nightly, weekly) this is the normal healthy state for most of '
            . 'the day. Never report a broken or missing schedule on that basis '
            . 'alone: if `alive` is true, cron itself is running, and the '
            . 'per-group `retention` numbers tell you how far back the evidence '
            . 'can possibly reach. Treat `stuck`, a non-null `last_error_at`, '
            . 'and a growing `pending` count as the real failure signals. '
            . 'All timestamps are ISO-8601 in UTC. `alive` is always measured '
            . 'over every job, even when `group` or `job_code` narrows the '
            . 'report; raising `stuck_threshold_minutes` past a group\'s '
            . '`success_minutes` cannot widen it, because older success rows '
            . 'have already been deleted.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('group', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(255)
                ->description('Only report jobs in this crontab group (e.g. '
                    . '`default`, `index`, `consumers`). Omit for every group.')
            )
            ->string('job_code', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(255)
                ->description('Only report this single job, e.g. '
                    . '`indexer_reindex_all_invalid`.')
            )
            ->integer('stuck_threshold_minutes', fn (IntegerBuilder $i) => $i
                ->minimum(self::MIN_THRESHOLD_MINUTES)
                ->maximum(self::MAX_THRESHOLD_MINUTES)
                ->description('A run still `running` for longer than this is '
                    . 'reported as `stuck`, and cron counts as alive only if '
                    . 'something succeeded within it. Default 60 minutes.')
            )
            ->integer('history_limit', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->maximum(self::MAX_HISTORY_LIMIT)
                ->description('Recent runs to list per job. Default 5, `0` '
                    . 'skips the history query entirely.')
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
        $groupFilter = $this->stringArgument($arguments, 'group');
        $jobCodeFilter = $this->stringArgument($arguments, 'job_code');
        $thresholdMinutes = $this->clampInt(
            $arguments['stuck_threshold_minutes'] ?? null,
            self::DEFAULT_THRESHOLD_MINUTES,
            self::MIN_THRESHOLD_MINUTES,
            self::MAX_THRESHOLD_MINUTES
        );
        $historyLimit = $this->clampInt(
            $arguments['history_limit'] ?? null,
            self::DEFAULT_HISTORY_LIMIT,
            0,
            self::MAX_HISTORY_LIMIT
        );

        $now = $this->dateTime->gmtTimestamp();
        $since = gmdate(self::DB_DATE_FORMAT, $now - (self::QUERY_WINDOW_DAYS * 86400));
        $stuckCutoff = $now - ($thresholdMinutes * 60);

        $configuredJobs = $this->configuredJobs();
        $totals = $this->groupTotalsByJob($this->scheduleReader->fetchStatusTotals($since));

        $jobs = [];
        foreach (array_keys($configuredJobs + $totals) as $jobCode) {
            $jobs[$jobCode] = $this->buildJob(
                (string) $jobCode,
                $configuredJobs[$jobCode] ?? null,
                $totals[$jobCode] ?? [],
                $stuckCutoff
            );
        }
        ksort($jobs);

        // Liveness is a global property of the cron runner, so it is computed
        // before any group/job filter narrows what gets reported.
        $alive = false;
        foreach ($jobs as $job) {
            if ($job['last_success_ts'] !== null && $job['last_success_ts'] >= $stuckCutoff) {
                $alive = true;
                break;
            }
        }

        $scoped = $this->applyFilters($jobs, $groupFilter, $jobCodeFilter);
        $scopedCodes = array_keys($scoped);

        $scoped = $this->attachErrorMessages($scoped, $since);
        $scoped = $this->attachRecentRuns($scoped, $scopedCodes, $since, $historyLimit);

        $groups = $this->summarizeGroups($scoped, $stuckCutoff);
        $summary = [
            'failing' => 0,
            'stuck' => 0,
            'no_run_history' => 0,
        ];
        foreach ($scoped as $job) {
            $summary['failing'] += $job['error'] > 0 ? 1 : 0;
            $summary['stuck'] += $job['stuck'] ? 1 : 0;
            $summary['no_run_history'] += $job['no_run_history'] ? 1 : 0;
        }

        $payload = [
            'alive' => $alive,
            'checked_at' => gmdate(DATE_ATOM, $now),
            'stuck_threshold_minutes' => $thresholdMinutes,
            'query_window_days' => self::QUERY_WINDOW_DAYS,
            'groups' => $groups === [] ? new stdClass() : $groups,
            'jobs' => $this->sortForOutput($scoped),
            'summary' => $summary,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the cron status report as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'alive' => $alive,
                'jobs' => count($scoped),
                'failing' => $summary['failing'],
                'stuck' => $summary['stuck'],
                'no_run_history' => $summary['no_run_history'],
            ]
        );
    }

    /**
     * Flattens `ConfigInterface::getJobs()` (group => job_code => config) into
     * job_code => {group, schedule}.
     *
     * @phpstan-return array<string, array{group: string, schedule: string|null}>
     * @return array[]
     */
    private function configuredJobs(): array
    {
        $jobs = [];
        foreach ($this->cronConfig->getJobs() as $group => $groupJobs) {
            if (!is_string($group) || !is_array($groupJobs)) {
                continue;
            }
            foreach ($groupJobs as $jobCode => $config) {
                if (!is_string($jobCode) || $jobCode === '') {
                    continue;
                }
                $schedule = is_array($config) ? ($config['schedule'] ?? null) : null;
                $jobs[$jobCode] = [
                    'group' => $group,
                    'schedule' => is_string($schedule) && $schedule !== '' ? $schedule : null,
                ];
            }
        }

        return $jobs;
    }

    /**
     * @phpstan-param list<array{
     *     job_code: string,
     *     status: string,
     *     run_count: int,
     *     last_scheduled_at: string|null,
     *     last_executed_at: string|null,
     *     last_finished_at: string|null,
     *     oldest_executed_at: string|null
     * }> $totals
     * @param array $totals
     * @phpstan-return array<string, array<string, array{
     *     run_count: int,
     *     last_scheduled_at: string|null,
     *     last_executed_at: string|null,
     *     last_finished_at: string|null,
     *     oldest_executed_at: string|null
     * }>>
     * @return array[]
     */
    private function groupTotalsByJob(array $totals): array
    {
        $byJob = [];
        foreach ($totals as $row) {
            $byJob[$row['job_code']][$row['status']] = [
                'run_count' => $row['run_count'],
                'last_scheduled_at' => $row['last_scheduled_at'],
                'last_executed_at' => $row['last_executed_at'],
                'last_finished_at' => $row['last_finished_at'],
                'oldest_executed_at' => $row['oldest_executed_at'],
            ];
        }

        return $byJob;
    }

    /**
     * @param string $jobCode
     * @phpstan-param array{group: string, schedule: string|null}|null $config
     * @param array|null $config
     * @phpstan-param array<string, array{
     *     run_count: int,
     *     last_scheduled_at: string|null,
     *     last_executed_at: string|null,
     *     last_finished_at: string|null,
     *     oldest_executed_at: string|null
     * }> $statuses
     * @param array $statuses
     * @param int $stuckCutoff
     * @phpstan-return array<string, mixed>
     * @return array
     */
    private function buildJob(string $jobCode, ?array $config, array $statuses, int $stuckCutoff): array
    {
        $success = $statuses[Schedule::STATUS_SUCCESS] ?? null;
        $error = $statuses[Schedule::STATUS_ERROR] ?? null;
        $running = $statuses[Schedule::STATUS_RUNNING] ?? null;

        $lastSuccessAt = $success !== null
            ? ($success['last_finished_at'] ?? $success['last_scheduled_at'])
            : null;
        $lastErrorAt = $error !== null
            ? ($error['last_finished_at'] ?? $error['last_scheduled_at'])
            : null;

        $oldestRunningAt = $running !== null ? $running['oldest_executed_at'] : null;
        $oldestRunningTs = $this->toTimestamp($oldestRunningAt);

        return [
            'job_code' => $jobCode,
            'group' => $config['group'] ?? self::UNKNOWN_GROUP,
            'configured' => $config !== null,
            'schedule' => $config['schedule'] ?? null,
            'last_success_at' => $this->toIso($lastSuccessAt),
            'last_error_at' => $this->toIso($lastErrorAt),
            'last_error_message' => null,
            'pending' => $this->runCount($statuses, Schedule::STATUS_PENDING),
            'running' => $this->runCount($statuses, Schedule::STATUS_RUNNING),
            'missed' => $this->runCount($statuses, Schedule::STATUS_MISSED),
            'error' => $this->runCount($statuses, Schedule::STATUS_ERROR),
            'stuck' => $oldestRunningTs !== null && $oldestRunningTs < $stuckCutoff,
            'no_run_history' => $statuses === [],
            'recent' => [],
            'last_success_ts' => $this->toTimestamp($lastSuccessAt),
        ];
    }

    /**
     * @phpstan-param array<string, array{run_count: int}|array<string, mixed>> $statuses
     * @param array $statuses
     * @param string $status
     * @return int
     */
    private function runCount(array $statuses, string $status): int
    {
        $count = $statuses[$status]['run_count'] ?? 0;

        return is_int($count) ? $count : 0;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @param string|null $group
     * @param string|null $jobCode
     * @phpstan-return array<string, array<string, mixed>>
     * @return array[]
     * @throws LocalizedException
     */
    private function applyFilters(array $jobs, ?string $group, ?string $jobCode): array
    {
        // Captured before narrowing so an unknown-group error can still list
        // every real group, even when `job_code` already shrank the set to one.
        $knownGroups = $this->knownGroups($jobs);

        // Validated before the job filter narrows the set, so a real group is
        // never reported as unknown just because `job_code` excluded it.
        if ($group !== null && !in_array($group, $knownGroups, true)) {
            throw new LocalizedException(__(
                'Unknown cron group "%1". Known groups: %2.',
                $group,
                implode(', ', $knownGroups)
            ));
        }

        if ($jobCode !== null) {
            if (!isset($jobs[$jobCode])) {
                throw new LocalizedException(__(
                    'Unknown cron job "%1" — it is not in any crontab group and has no schedule history.',
                    $jobCode
                ));
            }
            $jobGroup = $jobs[$jobCode]['group'] ?? null;
            if ($group !== null && $jobGroup !== $group) {
                throw new LocalizedException(__(
                    'Cron job "%1" is in group "%2", not "%3".',
                    $jobCode,
                    is_string($jobGroup) ? $jobGroup : self::UNKNOWN_GROUP,
                    $group
                ));
            }

            return [$jobCode => $jobs[$jobCode]];
        }

        if ($group === null) {
            return $jobs;
        }

        $filtered = [];
        foreach ($jobs as $code => $job) {
            if (($job['group'] ?? null) === $group) {
                $filtered[$code] = $job;
            }
        }

        return $filtered;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @phpstan-return list<string>
     * @return string[]
     */
    private function knownGroups(array $jobs): array
    {
        $groups = [];
        foreach ($jobs as $job) {
            $group = $job['group'] ?? null;
            if (is_string($group)) {
                $groups[$group] = true;
            }
        }
        $names = array_keys($groups);
        sort($names);

        return $names;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @param string $since
     * @phpstan-return array<string, array<string, mixed>>
     * @return array[]
     */
    private function attachErrorMessages(array $jobs, string $since): array
    {
        $failing = [];
        foreach ($jobs as $code => $job) {
            if (($job['error'] ?? 0) > 0) {
                $failing[] = $code;
            }
        }
        if ($failing === []) {
            return $jobs;
        }

        $rows = $this->scheduleReader->fetchLatestErrors(
            $failing,
            $since,
            min(count($failing) * self::ERROR_ROWS_PER_JOB, self::MAX_ERROR_ROWS)
        );
        foreach ($rows as $row) {
            $code = $row['job_code'];
            // Rows arrive newest-first, so the first hit per job wins.
            if (!isset($jobs[$code]) || $jobs[$code]['last_error_message'] !== null) {
                continue;
            }
            $message = trim((string) $row['messages']);
            if ($message === '') {
                continue;
            }
            $jobs[$code]['last_error_message'] = mb_substr($message, 0, self::MAX_ERROR_MESSAGE_LENGTH);
        }

        return $jobs;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @phpstan-param list<string> $jobCodes
     * @param array $jobCodes
     * @param string $since
     * @param int $historyLimit
     * @phpstan-return array<string, array<string, mixed>>
     * @return array[]
     */
    private function attachRecentRuns(array $jobs, array $jobCodes, string $since, int $historyLimit): array
    {
        if ($historyLimit < 1 || $jobCodes === []) {
            return $jobs;
        }

        $rows = $this->scheduleReader->fetchRecentRuns(
            $jobCodes,
            $since,
            min(count($jobCodes) * $historyLimit, self::MAX_HISTORY_ROWS)
        );
        foreach ($rows as $row) {
            $code = $row['job_code'];
            if (!isset($jobs[$code]) || !is_array($jobs[$code]['recent'])) {
                continue;
            }
            if (count($jobs[$code]['recent']) >= $historyLimit) {
                continue;
            }
            $jobs[$code]['recent'][] = [
                'status' => $row['status'],
                'scheduled_at' => $this->toIso($row['scheduled_at']),
                'executed_at' => $this->toIso($row['executed_at']),
                'finished_at' => $this->toIso($row['finished_at']),
            ];
        }

        return $jobs;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @param int $stuckCutoff
     * @phpstan-return array<string, array<string, mixed>>
     * @return array[]
     */
    private function summarizeGroups(array $jobs, int $stuckCutoff): array
    {
        $groups = [];
        foreach ($jobs as $job) {
            $group = is_string($job['group'] ?? null) ? $job['group'] : self::UNKNOWN_GROUP;
            $groups[$group] ??= [
                'jobs' => 0,
                'pending' => 0,
                'running' => 0,
                'missed' => 0,
                'error' => 0,
                'stuck' => 0,
                'failing' => 0,
                'no_run_history' => 0,
                'last_success_at' => null,
                'alive' => false,
                'last_success_ts' => null,
            ];

            $groups[$group]['jobs']++;
            foreach (['pending', 'running', 'missed', 'error'] as $key) {
                $count = $job[$key] ?? 0;
                $groups[$group][$key] += is_int($count) ? $count : 0;
            }
            $groups[$group]['stuck'] += ($job['stuck'] ?? false) === true ? 1 : 0;
            $groups[$group]['failing'] += ($job['error'] ?? 0) > 0 ? 1 : 0;
            $groups[$group]['no_run_history'] += ($job['no_run_history'] ?? false) === true ? 1 : 0;

            $successTs = $job['last_success_ts'] ?? null;
            $currentTs = $groups[$group]['last_success_ts'];
            if (is_int($successTs) && (!is_int($currentTs) || $successTs > $currentTs)) {
                $groups[$group]['last_success_ts'] = $successTs;
                $groups[$group]['last_success_at'] = $job['last_success_at'] ?? null;
            }
        }

        foreach ($groups as $group => $data) {
            $successTs = $data['last_success_ts'];
            $groups[$group]['alive'] = is_int($successTs) && $successTs >= $stuckCutoff;
            unset($groups[$group]['last_success_ts']);
            $groups[$group]['retention'] = $this->groupRetention((string) $group);
        }
        ksort($groups);

        return $groups;
    }

    /**
     * How long this group's rows survive Magento's own history cleanup, and how
     * far ahead it queues runs — without these, an absent history is unreadable.
     *
     * @param string $group
     * @phpstan-return array{
     *     success_minutes: int,
     *     failure_minutes: int,
     *     schedule_ahead_minutes: int
     * }|null
     * @return array|null
     */
    private function groupRetention(string $group): ?array
    {
        $success = $this->cronGroupSetting($group, ProcessCronQueueObserver::XML_PATH_HISTORY_SUCCESS);
        $failure = $this->cronGroupSetting($group, ProcessCronQueueObserver::XML_PATH_HISTORY_FAILURE);
        $ahead = $this->cronGroupSetting($group, ProcessCronQueueObserver::XML_PATH_SCHEDULE_AHEAD_FOR);

        // Orphan jobs bucket under a group that has no crontab entry, so there
        // is no retention policy to report rather than a misleading zero.
        if ($success === null && $failure === null && $ahead === null) {
            return null;
        }

        return [
            'success_minutes' => $success ?? 0,
            'failure_minutes' => $failure ?? 0,
            'schedule_ahead_minutes' => $ahead ?? 0,
        ];
    }

    /**
     * @param string $group
     * @param string $field
     * @return int|null
     */
    private function cronGroupSetting(string $group, string $field): ?int
    {
        $value = $this->scopeConfig->getValue(
            sprintf(self::CONFIG_PATH_TEMPLATE, $group, $field),
            ScopeInterface::SCOPE_STORE
        );

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Problems first — stuck, then failing, then jobs with no surviving history
     * — so a client reading top-down sees the answer before the healthy noise.
     *
     * @phpstan-param array<string, array<string, mixed>> $jobs
     * @param array $jobs
     * @phpstan-return list<array<string, mixed>>
     * @return array[]
     */
    private function sortForOutput(array $jobs): array
    {
        $rows = array_values($jobs);
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $job): int => match (true) {
                ($job['stuck'] ?? false) === true => 0,
                ($job['error'] ?? 0) > 0 => 1,
                ($job['no_run_history'] ?? false) === true => 2,
                default => 3,
            };
            $code = static fn (array $job): string => is_string($job['job_code'] ?? null)
                ? $job['job_code']
                : '';
            return $rank($a) <=> $rank($b) ?: strcmp($code($a), $code($b));
        });

        foreach ($rows as $index => $row) {
            unset($row['last_success_ts']);
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * @phpstan-param array<string, mixed> $arguments
     * @param array $arguments
     * @param string $key
     * @return string|null
     * @throws LocalizedException
     */
    private function stringArgument(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new LocalizedException(__('Argument "%1" must be a string.', $key));
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || !is_scalar($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * `cron_schedule` stores UTC without an offset — restate it explicitly so
     * clients never have to guess the zone.
     *
     * @param string|null $dbDate
     * @return string|null
     */
    private function toIso(?string $dbDate): ?string
    {
        $parsed = $this->parse($dbDate);

        return $parsed?->format(DATE_ATOM);
    }

    /**
     * @param string|null $dbDate
     * @return int|null
     */
    private function toTimestamp(?string $dbDate): ?int
    {
        return $this->parse($dbDate)?->getTimestamp();
    }

    /**
     * @param string|null $dbDate
     * @return DateTimeImmutable|null
     */
    private function parse(?string $dbDate): ?DateTimeImmutable
    {
        if ($dbDate === null || $dbDate === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            self::DB_DATE_FORMAT,
            $dbDate,
            new DateTimeZone('UTC')
        );

        return $parsed === false ? null : $parsed;
    }
}
