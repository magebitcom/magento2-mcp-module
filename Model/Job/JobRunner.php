<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditContextFactory;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\BulkExecutor;
use Magebit\Mcp\Model\Tool\Bulk\ItemAuditCollector;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use RuntimeException;
use Throwable;

/**
 * Queue consumer handler for `magebit.mcp.job.run` (consumer `magebit_mcp_job_runner`); the message is a job id.
 *
 * Steps: claim, re-check access, snapshot, run the items from `next_index`, finish, audit. Only the worker that
 * wins the claim does anything, so duplicate and redelivered messages are harmless. Each item runs at most once:
 * an in-flight marker is written before it runs ({@see JobRepository::markItemRunning()}), and an item whose
 * worker stopped mid-item is reported `INTERRUPTED` instead of being run again. After the claim nothing is
 * thrown: a throwing handler makes the consumer reject or requeue the message, which would loop. Job errors are
 * shown to agents through `system_job_get`, so only LocalizedException messages and fixed texts are stored.
 */
class JobRunner
{
    public const AUDIT_METHOD = 'job';

    private const MESSAGE_UNEXPECTED = 'The job stopped unexpectedly; see the store log.';
    private const MESSAGE_UNREADABLE_INPUT = 'The stored input of this job could not be read.';
    private const MESSAGE_SNAPSHOT_UNEXPECTED = 'the snapshot could not be taken; see the store log.';

    /**
     * @param JobRepository $jobs
     * @param JobAccessGuard $accessGuard
     * @param AuthenticatedContextProvider $contextProvider
     * @param BulkExecutor $executor
     * @param SnapshotProviderPool $snapshotProviders
     * @param State $appState
     * @param AuditLogger $auditLogger
     * @param AuditContextFactory $auditContextFactory
     * @param LoggerInterface $logger
     * @param IdentityGeneratorInterface $identityGenerator
     * @param JobItemKeys $itemKeys
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobAccessGuard $accessGuard,
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly BulkExecutor $executor,
        private readonly SnapshotProviderPool $snapshotProviders,
        private readonly State $appState,
        private readonly AuditLogger $auditLogger,
        private readonly AuditContextFactory $auditContextFactory,
        private readonly LoggerInterface $logger,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly JobItemKeys $itemKeys
    ) {
    }

    /**
     * @param string $jobId
     * @return void
     */
    public function process(string $jobId): void
    {
        // An unknown id, a duplicate message or a second consumer all lose the claim and end here. The run id
        // makes every later write of this worker conditional on it still owning the job.
        $runId = $this->identityGenerator->generateId();
        if (!$this->jobs->claim($jobId, $runId)) {
            return;
        }

        $startedAt = microtime(true);
        // One fresh context per job: the shared request-scoped one would carry state between jobs.
        $audit = $this->auditContextFactory->create();
        $audit->method = self::AUDIT_METHOD;
        // Per-item audit detail of the items this run processes (only for tools that give it).
        $itemAudit = new ItemAuditCollector();
        try {
            $job = $this->jobs->getByJobId($jobId);
            $audit->toolName = $job->getToolName();
            $audit->adminUserId = $job->getAdminUserId();
            $audit->tokenId = $job->getTokenId();
            $this->appState->emulateAreaCode(
                Area::AREA_ADMINHTML,
                fn () => $this->runClaimed($jobId, $runId, $job, $itemAudit)
            );
        } catch (Throwable $e) {
            $this->failAfterThrowable($jobId, $runId, $e);
        } finally {
            $this->writeAudit($audit, $jobId, $startedAt, $itemAudit);
        }
    }

    /**
     * @param string $jobId
     * @param string $runId
     * @param JobInterface $job
     * @param ItemAuditCollector $itemAudit
     * @return void
     * @throws Throwable Handled by process().
     */
    private function runClaimed(string $jobId, string $runId, JobInterface $job, ItemAuditCollector $itemAudit): void
    {
        try {
            [$adapter, $context] = $this->accessGuard->authorize($job);
        } catch (ForbiddenException $e) {
            $this->finishJob($jobId, $runId, JobInterface::STATE_FAILED, 'FORBIDDEN: ' . $e->getMessage());
            return;
        }
        // The same identity a live tools/call hands to context-aware tools.
        $this->contextProvider->set($context);

        $items = $this->readItems($job);
        if ($items === null) {
            $this->finishJob($jobId, $runId, JobInterface::STATE_FAILED, self::MESSAGE_UNREADABLE_INPUT);
            return;
        }
        $tool = $adapter->getBulkTool();
        if (!$this->takeSnapshot($jobId, $runId, $job, $tool, $items)) {
            return;
        }
        // A slow snapshot may have let the stale cron fail the job; refresh the heartbeat, or stop if lost.
        if (!$this->jobs->heartbeat($jobId, $runId)) {
            $this->warnLost($jobId, $job->getNextIndex());
            return;
        }
        $this->runItems($jobId, $runId, $job, $tool, $items, $context->adminUser, $itemAudit);
    }

    /**
     * @param JobInterface $job
     * @return array<int, array<string, mixed>>|null Null when an item is not an object or the count is off.
     */
    private function readItems(JobInterface $job): ?array
    {
        $items = [];
        foreach (array_values($job->getItems()) as $item) {
            if (!is_array($item)) {
                return null;
            }
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }
        return count($items) === $job->getTotal() ? $items : null;
    }

    /**
     * First run of a non-dry-run job only. A failure fails the job before any item ran, so it can be resumed.
     * A job resumed at `next_index` 0 (its worker died during the first item) may already have its snapshot;
     * that one holds the before-state, so the export is skipped.
     *
     * @param string $jobId
     * @param string $runId
     * @param JobInterface $job
     * @param BulkToolInterface $tool
     * @param array<int, array<string, mixed>> $items
     * @return bool False when the job was failed here.
     */
    private function takeSnapshot(
        string $jobId,
        string $runId,
        JobInterface $job,
        BulkToolInterface $tool,
        array $items
    ): bool {
        if ($job->getNextIndex() !== 0 || $job->isDryRun()) {
            return true;
        }
        $provider = $this->snapshotProviders->get($job->getToolName());
        if ($provider === null || $this->jobs->hasSnapshot($jobId)) {
            return true;
        }
        try {
            $payload = $provider->export($tool, $items);
        } catch (Throwable $e) {
            $this->logger->error('MCP job snapshot failed.', ['job_id' => $jobId, 'exception' => $e]);
            $reason = $e instanceof LocalizedException ? $e->getMessage() : self::MESSAGE_SNAPSHOT_UNEXPECTED;
            $this->finishJob($jobId, $runId, JobInterface::STATE_FAILED, 'Snapshot failed: ' . $reason);
            return false;
        }
        $this->jobs->saveSnapshot($jobId, $provider->getFormat(), $payload);
        return true;
    }

    /**
     * @param string $jobId
     * @param string $runId
     * @param JobInterface $job
     * @param BulkToolInterface $tool
     * @param array<int, array<string, mixed>> $items
     * @param User $admin
     * @param ItemAuditCollector $itemAudit
     * @return void
     * @throws Throwable A persistence error from marking or recording an item, rethrown once afterBatch has run.
     */
    private function runItems(
        string $jobId,
        string $runId,
        JobInterface $job,
        BulkToolInterface $tool,
        array $items,
        User $admin,
        ItemAuditCollector $itemAudit
    ): void {
        // Set by the callbacks below; typed because PHPStan does not follow by-reference closure writes.
        /** @var bool $lost */
        $lost = false;
        /** @var bool $cancelled */
        $cancelled = false;
        /** @var Throwable|null $recordError */
        $recordError = null;
        $firstCheck = true;
        $nextIndex = $job->getNextIndex();

        try {
            // The in-flight marker. A throw stops the run before the item; the executor raises it after afterBatch.
            // A dry run changes nothing, so it needs no marker: an interrupted dry-run item is checked again.
            $beforeItem = null;
            if (!$job->isDryRun()) {
                $beforeItem = function (int $index, ?string $key) use ($jobId, $runId, &$lost): ?ItemResult {
                    $mark = $this->jobs->markItemRunning($jobId, $runId, $index, $key);
                    if ($mark === JobRepository::MARK_LOST) {
                        $lost = true;
                        throw new RuntimeException('MCP job run no longer owns its job.');
                    }
                    return $mark === JobRepository::MARK_INTERRUPTED
                        ? ItemResult::error(BulkErrorCode::INTERRUPTED, JobRepository::MESSAGE_INTERRUPTED)
                        : null;
                };
            }
            $result = $this->executor->run(
                $tool,
                $items,
                $job->isDryRun(),
                $admin,
                $nextIndex,
                /**
                 * @param array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null} $row
                 */
                function (int $index, array $row) use ($jobId, $runId, &$lost, &$recordError, &$nextIndex): void {
                    // Kept, not thrown: a throw would skip the tool's afterBatch. shouldStop ends the loop.
                    try {
                        if ($this->jobs->recordItem($jobId, $runId, $row, $index + 1)) {
                            $nextIndex = $index + 1;
                        } else {
                            $lost = true;
                        }
                    } catch (Throwable $e) {
                        $recordError = $e;
                    }
                },
                function () use ($jobId, $runId, &$lost, &$cancelled, &$recordError, &$firstCheck): bool {
                    if ($lost || $recordError !== null) {
                        return true;
                    }
                    // The first check comes after beforeBatch, which may have been slow too.
                    if ($firstCheck) {
                        $firstCheck = false;
                        if (!$this->jobs->heartbeat($jobId, $runId)) {
                            $lost = true;
                            return true;
                        }
                    }
                    if ($this->jobs->isCancelRequested($jobId)) {
                        $cancelled = true;
                        return true;
                    }
                    return false;
                },
                // afterBatch may be slow (a reindex): refresh the heartbeat first so the stale cron leaves the job
                // alone, and skip it when this run no longer owns the job. A heartbeat that throws (often the same
                // database fault as a record error) must not skip afterBatch either: keep the first error, run it.
                function () use ($jobId, $runId, &$lost, &$recordError): bool {
                    if ($lost) {
                        return false;
                    }
                    try {
                        if (!$this->jobs->heartbeat($jobId, $runId)) {
                            $lost = true;
                            return false;
                        }
                    } catch (Throwable $e) {
                        $recordError ??= $e;
                    }
                    return true;
                },
                $itemAudit,
                $beforeItem
            );
        } catch (Throwable $e) {
            // Lost at the marker: nothing more is written, as for a run lost anywhere else.
            if (!$lost) {
                throw $e;
            }
            $this->warnLost($jobId, $nextIndex);
            return;
        }
        // Kept in the error column of a completed or cancelled job; system_job_get shows it as `notice`.
        $notice = $result->getNotice();

        if ($recordError !== null) {
            throw $recordError;
        }
        if ($lost) {
            $this->warnLost($jobId, $nextIndex);
            return;
        }
        if (!$cancelled) {
            $this->finishJob($jobId, $runId, JobInterface::STATE_COMPLETED, $notice);
            return;
        }
        if ($this->finishJob($jobId, $runId, JobInterface::STATE_CANCELLED, $notice)) {
            $this->jobs->markRemainingCancelled(
                $jobId,
                $nextIndex,
                $this->itemKeys->fromItems($items, $nextIndex, $tool->getItemKeyField())
            );
        }
    }

    /**
     * @param string $jobId
     * @param int $nextIndex
     * @return void
     */
    private function warnLost(string $jobId, int $nextIndex): void
    {
        $this->logger->warning(
            'MCP job was taken from this worker while it ran; stopped without finishing it.',
            ['job_id' => $jobId, 'next_index' => $nextIndex]
        );
    }

    /**
     * @param string $jobId
     * @param string $runId
     * @param string $state
     * @param string|null $error
     * @return bool False when this run no longer owns the job; another actor owns its state then.
     */
    private function finishJob(string $jobId, string $runId, string $state, ?string $error): bool
    {
        if ($this->jobs->finish($jobId, $runId, $state, $error)) {
            return true;
        }
        $this->logger->warning(
            'MCP job was no longer running when this worker finished it; left as it is.',
            ['job_id' => $jobId, 'state' => $state]
        );
        return false;
    }

    /**
     * @param string $jobId
     * @param string $runId
     * @param Throwable $exception
     * @return void
     */
    private function failAfterThrowable(string $jobId, string $runId, Throwable $exception): void
    {
        if ($exception instanceof ForbiddenException) {
            $error = 'FORBIDDEN: ' . $exception->getMessage();
        } elseif ($exception instanceof LocalizedException) {
            $error = $exception->getMessage();
        } else {
            // Raw text may hold SQL, paths or input values; it goes to the log, never onto the job.
            $this->logger->error('MCP job stopped unexpectedly.', ['job_id' => $jobId, 'exception' => $exception]);
            $error = self::MESSAGE_UNEXPECTED;
        }
        try {
            $this->finishJob($jobId, $runId, JobInterface::STATE_FAILED, $error);
        } catch (Throwable $e) {
            $this->logger->error('MCP job could not be marked failed.', ['job_id' => $jobId, 'exception' => $e]);
        }
    }

    /**
     * One row per claimed job, whatever the outcome. Never throws.
     *
     * @param AuditContext $audit
     * @param string $jobId
     * @param float $startedAt
     * @param ItemAuditCollector $itemAudit
     * @return void
     */
    private function writeAudit(
        AuditContext $audit,
        string $jobId,
        float $startedAt,
        ItemAuditCollector $itemAudit
    ): void {
        $audit->durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $audit->resultSummary = ['job_id' => $jobId];
        $audit->responseStatus = AuditEntryInterface::STATUS_ERROR;
        try {
            $job = $this->jobs->getByJobId($jobId);
            $audit->resultSummary = [
                'job_id' => $jobId,
                'state' => $job->getState(),
                'total' => $job->getTotal(),
                'ok' => $job->getOk(),
                'failed' => $job->getFailed(),
                'skipped' => $job->getSkipped(),
            ];
            $audit->responseStatus = $job->getState() === JobInterface::STATE_FAILED
                ? AuditEntryInterface::STATUS_ERROR
                : AuditEntryInterface::STATUS_OK;
        } catch (Throwable $e) {
            // Still audited, as an error with the job id only.
            $this->logger->error('MCP job could not be re-read for its audit row.', [
                'job_id' => $jobId,
                'exception' => $e,
            ]);
        }
        $audit->resultSummary = ($audit->resultSummary ?? []) + $itemAudit->toSummary();
        try {
            $this->auditLogger->write($audit);
        } catch (Throwable $e) {
            $this->logger->error('MCP job audit row could not be written.', ['job_id' => $jobId, 'exception' => $e]);
        }
    }
}
