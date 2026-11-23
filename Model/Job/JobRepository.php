<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\ResourceModel\Job as JobResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * Persistence for background jobs, their item rows and snapshots.
 *
 * Every write that races another actor (claim, cancel, requeue) is one conditional UPDATE, and every
 * timestamp comes from the DB clock so PHP and DB time zones never mix. Rows are read and written
 * through the resource model's connection; the model's own save/load are never used.
 */
class JobRepository
{
    /**
     * {@see self::markItemRunning()}: the marker is written, run the item.
     */
    public const MARK_RUN = 'run';
    /**
     * {@see self::markItemRunning()}: a worker stopped while this item ran; report it, never run it again.
     */
    public const MARK_INTERRUPTED = 'interrupted';
    /**
     * {@see self::markItemRunning()}: this run no longer owns the job; nothing was written.
     */
    public const MARK_LOST = 'lost';

    /**
     * Internal status of the in-flight marker. Never shown: item pages and counters leave it out, and it becomes
     * error / INTERRUPTED when the job leaves `running`.
     */
    public const ITEM_STATUS_RUNNING = 'running';

    /**
     * Largest stored job input, in bytes: the largest request body the module accepts
     * ({@see ModuleConfig::MAX_MAX_BODY_KB}) times 3, the most the escaped encoding can grow it (a two-byte
     * UTF-8 character becomes a six-byte `\uXXXX`, a four-byte one a twelve-byte surrogate pair). 96 MiB, far
     * under the `items_json` LONGTEXT limit (4 GiB - 1), so the column holds any accepted request; a larger
     * input is refused before the insert with a clear message. The single INSERT is still bounded by the
     * database's `max_allowed_packet` (often 16 to 64 MiB), so on a store that raised the body limit far above
     * the default, an input under this ceiling can still be refused by the database driver.
     */
    public const MAX_INPUT_BYTES = ModuleConfig::MAX_MAX_BODY_KB * 1024 * 3;

    public const MESSAGE_INTERRUPTED = 'The worker stopped while this item was running; it may already have been '
        . 'applied. Check it and send it again in a new call if needed.';

    private const ITEM_TABLE = 'magebit_mcp_job_item';
    private const ITEM_STATUS_ERROR = 'error';
    private const CODE_INTERRUPTED = 'INTERRUPTED';
    private const SNAPSHOT_TABLE = 'magebit_mcp_job_snapshot';
    private const ITEM_KEY_LENGTH = 255;
    private const ITEM_CODE_LENGTH = 64;

    /**
     * Job counter column by the item status it counts. The in-flight marker (`running`) has none.
     */
    private const COUNTER_BY_STATUS = ['ok' => 'ok', 'error' => 'failed', 'skipped' => 'skipped'];

    /**
     * Everything except the (potentially large) input, for list and stale reads.
     */
    private const LIST_COLUMNS = [
        'entity_id', 'job_id', 'tool_name', 'admin_user_id', 'token_id', 'oauth_client_id', 'state', 'run_id',
        'dry_run',
        'total', 'ok', 'failed', 'skipped', 'next_index', 'cancel_requested', 'error',
        'created_at', 'started_at', 'heartbeat_at', 'finished_at',
    ];

    /**
     * What the revoke cleanup needs to mark a cancelled job's items ({@see JobCanceller}, {@see JobItemKeys}).
     */
    private const CLEANUP_COLUMNS = ['entity_id', 'job_id', 'tool_name', 'state', 'next_index', 'total', 'items_json'];

    /**
     * @param JobResource $resource
     * @param JobFactory $jobFactory
     * @param IdentityGeneratorInterface $identityGenerator
     * @param int $maxInputBytes {@see self::MAX_INPUT_BYTES}
     */
    public function __construct(
        private readonly JobResource $resource,
        private readonly JobFactory $jobFactory,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly int $maxInputBytes = self::MAX_INPUT_BYTES
    ) {
    }

    /**
     * Stores a new `queued` job.
     *
     * The input is stored as escaped JSON (no JSON_UNESCAPED_UNICODE): pure ASCII, so it fits the column whatever
     * its charset, and its byte length is what {@see self::MAX_INPUT_BYTES} bounds.
     *
     * @param string $toolName
     * @param int $adminUserId
     * @param int $tokenId
     * @param int|null $oauthClientId
     * @param bool $dryRun
     * @param array<int, mixed> $items
     * @return JobInterface
     * @throws LocalizedException When the encoded input is over the stored-input ceiling.
     */
    public function create(
        string $toolName,
        int $adminUserId,
        int $tokenId,
        ?int $oauthClientId,
        bool $dryRun,
        array $items
    ): JobInterface {
        $itemsJson = json_encode(array_values($items), JSON_THROW_ON_ERROR);
        if (strlen($itemsJson) > $this->maxInputBytes) {
            throw new LocalizedException(
                __('The job input is too large to store; send fewer items per call.')
            );
        }
        $row = [
            'job_id' => $this->identityGenerator->generateId(),
            'tool_name' => $toolName,
            'admin_user_id' => $adminUserId,
            'token_id' => $tokenId,
            'oauth_client_id' => $oauthClientId,
            'state' => JobInterface::STATE_QUEUED,
            'dry_run' => $dryRun ? 1 : 0,
            'items_json' => $itemsJson,
            'total' => count($items),
            'next_index' => 0,
        ];
        $this->connection()->insert($this->resource->getMainTable(), $row);
        return $this->jobFactory->create(['data' => $row]);
    }

    /**
     * @param string $jobId
     * @return JobInterface
     * @throws NoSuchEntityException
     */
    public function getByJobId(string $jobId): JobInterface
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getMainTable())
            ->where('job_id = ?', $jobId);
        $row = $connection->fetchRow($select);
        if (!is_array($row) || $row === []) {
            throw NoSuchEntityException::singleField('job_id', $jobId);
        }
        return $this->jobFactory->create(['data' => $row]);
    }

    /**
     * Atomic queued -> running, owned by $runId. False when another worker has the job or it is not runnable.
     *
     * Every later worker write (recordItem, heartbeat, finish) is guarded by this run id, so a run that lost the
     * job (stale cron, then a resume claimed by another worker) cannot write into the new run.
     *
     * @param string $jobId
     * @param string $runId Unique per claim.
     * @return bool
     */
    public function claim(string $jobId, string $runId): bool
    {
        $rows = $this->connection()->update(
            $this->resource->getMainTable(),
            [
                'state' => JobInterface::STATE_RUNNING,
                'run_id' => $runId,
                'started_at' => new Expression('NOW()'),
                'heartbeat_at' => new Expression('NOW()'),
            ],
            ['job_id = ?' => $jobId, 'state = ?' => JobInterface::STATE_QUEUED]
        );
        return (int) $rows === 1;
    }

    /**
     * Advances the job, then writes the item row (idempotent per item_index) and moves the counters, all in one
     * transaction: a failed item write rolls the cursor back, so a resume starts at the item that is missing.
     *
     * The ownership-guarded advance comes first so a run that lost the job never writes an item row: the new
     * owner's row for the same index is never overwritten. `next_index` always grows within a run, so the
     * advance changes the row whenever the run still owns the job. It also refreshes the heartbeat and holds
     * the job row's lock until the commit, which keeps the stale cron away while the row is written.
     *
     * The counters move by delta, never by a recount: the item row's previous status is read under that lock,
     * the new status adds one, and a replaced result subtracts one from its own counter, so a redelivered or
     * duplicate write never counts an item twice ({@see self::counterDelta()}).
     *
     * @param string $jobId
     * @param string $runId
     * @param array{index: int, key: ?string, status: string, error_code?: ?string, message?: ?string, id?: int|string|null} $row
     * @param int $nextIndex
     * @return bool False when this run no longer owns the job (lost to the stale cron, then possibly a resume
     *              claimed by another worker); nothing is written then and the runner must stop.
     */
    public function recordItem(string $jobId, string $runId, array $row, int $nextIndex): bool
    {
        $connection = $this->connection();
        $owned = $this->ownedRunWhere($jobId, $runId);
        $connection->beginTransaction();
        try {
            $rows = $connection->update(
                $this->resource->getMainTable(),
                ['next_index' => $nextIndex, 'heartbeat_at' => new Expression('NOW()')],
                $owned
            );
            if ((int) $rows !== 1) {
                // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
                $connection->commit();
                return false;
            }
            $previous = $this->itemStatus($connection, $jobId, $row['index']);
            $connection->insertOnDuplicate(
                $this->resource->getTable(self::ITEM_TABLE),
                [$this->itemRow($jobId, $row)],
                ['item_key', 'status', 'error_code', 'message', 'result_id']
            );
            $delta = $this->counterDelta($connection, $previous, $row['status']);
            if ($delta !== []) {
                $connection->update($this->resource->getMainTable(), $delta, $owned);
            }
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * Writes the in-flight marker of an item before it runs, so a job never runs an item twice.
     *
     * One transaction: the ownership-guarded heartbeat UPDATE locks the job row (a run that lost the job writes
     * nothing), then the item's row is read. A row left `running` (a worker stopped mid-item) or already reported
     * `INTERRUPTED` means the item may have been applied: nothing is written and the caller reports it instead of
     * running it. Otherwise the marker replaces whatever is there (a skipped/CANCELLED row from a cancel runs on
     * resume), and the replaced row's counter drops by one ({@see self::counterDelta()}), so the marker is never
     * counted.
     * {@see self::recordItem()} then replaces the marker with the result.
     *
     * @param string $jobId
     * @param string $runId
     * @param int $index
     * @param string|null $key
     * @return string One of the MARK_* constants.
     */
    public function markItemRunning(string $jobId, string $runId, int $index, ?string $key): string
    {
        $connection = $this->connection();
        $owned = $this->ownedRunWhere($jobId, $runId);
        $itemTable = $this->resource->getTable(self::ITEM_TABLE);
        $connection->beginTransaction();
        try {
            if (!$this->lockOwnedRun($connection, $owned)) {
                // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
                $connection->commit();
                return self::MARK_LOST;
            }
            // A plain read: every writer of this job's item rows takes the job row lock held above first, so it sees
            // them all; a locking read of a missing row would add an InnoDB gap lock shared with other jobs' inserts.
            $existing = $connection->fetchRow(
                $connection->select()
                    ->from($itemTable, ['status', 'error_code'])
                    ->where('job_id = ?', $jobId)
                    ->where('item_index = ?', $index)
            );
            if (is_array($existing) && $this->wasInterrupted($existing)) {
                $connection->commit();
                return self::MARK_INTERRUPTED;
            }
            $connection->insertOnDuplicate(
                $itemTable,
                [$this->itemRow($jobId, ['index' => $index, 'key' => $key, 'status' => self::ITEM_STATUS_RUNNING])],
                ['item_key', 'status', 'error_code', 'message', 'result_id']
            );
            $delta = $this->counterDelta(
                $connection,
                is_array($existing) ? $this->nullableString($existing['status'] ?? null) : null,
                self::ITEM_STATUS_RUNNING
            );
            if ($delta !== []) {
                $connection->update($this->resource->getMainTable(), $delta, $owned);
            }
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return self::MARK_RUN;
    }

    /**
     * Refreshes `heartbeat_at` for a run that still owns the job.
     *
     * @param string $jobId
     * @param string $runId
     * @return bool False when this run no longer owns the job.
     */
    public function heartbeat(string $jobId, string $runId): bool
    {
        $connection = $this->connection();
        $owned = $this->ownedRunWhere($jobId, $runId);
        $rows = $connection->update(
            $this->resource->getMainTable(),
            ['heartbeat_at' => new Expression('NOW()')],
            $owned
        );
        if ((int) $rows === 1) {
            return true;
        }
        // MySQL reports 0 changed rows when NOW() equals the stored second (a heartbeat right after the claim),
        // so a miss is confirmed with a read before the run is called lost.
        $select = $connection->select()->from($this->resource->getMainTable(), ['entity_id']);
        foreach ($owned as $condition => $value) {
            $select->where($condition, $value);
        }
        // fetchOne() gives false for no row; entity_id is never empty.
        return (string) $connection->fetchOne($select) !== '';
    }

    /**
     * Marks every item from $fromIndex to total-1 `skipped` / `CANCELLED` and recomputes the counters from the
     * item rows, for a job that is already cancelled.
     *
     * One transaction that first locks the job row and re-reads its state: a job resumed in the meantime is no
     * longer `cancelled` and is left alone, so the cleanup never overwrites a resumed run's results. While the
     * lock is held, {@see self::requeue()} (one UPDATE on the same row) waits, so a resume cannot start in the
     * middle of the cleanup.
     *
     * @param string $jobId
     * @param int $fromIndex
     * @param array<int, string|null> $keysByIndex Item keys by index, for display.
     * @return void
     */
    public function markRemainingCancelled(string $jobId, int $fromIndex, array $keysByIndex): void
    {
        $connection = $this->connection();
        $table = $this->resource->getMainTable();
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($table, ['state', 'total', 'next_index'])
                ->where('job_id = ?', $jobId)
                ->forUpdate(true);
            $job = $connection->fetchRow($select);
            if (!is_array($job) || ($job['state'] ?? null) !== JobInterface::STATE_CANCELLED) {
                // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
                $connection->commit();
                return;
            }
            $total = is_numeric($job['total'] ?? null) ? (int) $job['total'] : 0;
            // A later run may have advanced the cursor since the caller read it (cancel, resume, cancel again while
            // this cleanup waited): never mark items that run already finished.
            $lockedNext = is_numeric($job['next_index'] ?? null) ? (int) $job['next_index'] : 0;
            $fromIndex = max($fromIndex, $lockedNext);
            if ($fromIndex >= $total) {
                $connection->commit();
                return;
            }
            // An item a stopped worker may have applied stays INTERRUPTED: as skipped/CANCELLED a resume would run it.
            $keep = $this->interruptedIndexes($jobId, $fromIndex);
            $rows = [];
            for ($i = $fromIndex; $i < $total; $i++) {
                if (isset($keep[$i])) {
                    continue;
                }
                $rows[] = $this->itemRow($jobId, [
                    'index' => $i,
                    'key' => $keysByIndex[$i] ?? null,
                    'status' => 'skipped',
                    'error_code' => 'CANCELLED',
                    'message' => 'Job cancelled before this item ran.',
                ]);
            }
            if ($rows === []) {
                $connection->commit();
                return;
            }
            $connection->insertOnDuplicate(
                $this->resource->getTable(self::ITEM_TABLE),
                $rows,
                ['item_key', 'status', 'error_code', 'message', 'result_id']
            );
            $connection->update(
                $table,
                $this->counterColumns($jobId),
                ['job_id = ?' => $jobId, 'state = ?' => JobInterface::STATE_CANCELLED]
            );
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Ends a job. Only a `completed` job drops its input; failed and cancelled jobs keep it so they can
     * be resumed.
     *
     * @param string $jobId
     * @param string $runId The run that owns the job; the stale cron passes the run id it read.
     * @param string $state
     * @param string|null $error
     * An in-flight marker left by an item that never got its result becomes error / INTERRUPTED in the same
     * transaction ({@see self::interruptLeftovers()}).
     *
     * @return bool False when the job was not `running` under this run (a late worker lost it); nothing is
     *              written then.
     */
    public function finish(string $jobId, string $runId, string $state, ?string $error): bool
    {
        $data = [
            'state' => $state,
            'error' => $error,
            'finished_at' => new Expression('NOW()'),
        ];
        if ($state === JobInterface::STATE_COMPLETED) {
            $data['items_json'] = null;
        }
        return $this->leaveRunning($jobId, $data, $this->ownedRunWhere($jobId, $runId));
    }

    /**
     * Fails a running job for the stale cron, but only while its heartbeat is still older than $olderThanSeconds.
     * The owned-run guard and the heartbeat check are one UPDATE, so a heartbeat the worker sent after the stale
     * lookup keeps the run alive. Sets what {@see self::finish()} sets for `failed`; the input is kept for a resume.
     *
     * @param string $jobId
     * @param string $runId The run id the stale lookup read.
     * @param int $olderThanSeconds
     * @param string $error
     * @return bool False when the run sent a heartbeat meanwhile, or no longer owns the job; nothing is written.
     */
    public function failStale(string $jobId, string $runId, int $olderThanSeconds, string $error): bool
    {
        return $this->leaveRunning(
            $jobId,
            [
                'state' => JobInterface::STATE_FAILED,
                'error' => $error,
                'finished_at' => new Expression('NOW()'),
            ],
            $this->ownedRunWhere($jobId, $runId)
                + ['heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)' => $olderThanSeconds]
        );
    }

    /**
     * Moves a job out of `running` with one guarded UPDATE, then turns any leftover marker into error /
     * INTERRUPTED and recomputes the counters, in one transaction, so rows and counters agree at rest.
     *
     * @param string $jobId
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return bool False when the guarded UPDATE changed nothing; nothing is written then.
     */
    private function leaveRunning(string $jobId, array $data, array $where): bool
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $rows = $connection->update($this->resource->getMainTable(), $data, $where);
            if ((int) $rows === 1) {
                $this->interruptLeftovers($connection, $jobId);
            }
            // Commit even when nothing changed, so an enclosing transaction is never poisoned.
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return (int) $rows === 1;
    }

    /**
     * @param AdapterInterface $connection
     * @param string $jobId
     * @return void
     */
    private function interruptLeftovers(AdapterInterface $connection, string $jobId): void
    {
        $converted = $connection->update(
            $this->resource->getTable(self::ITEM_TABLE),
            [
                'status' => self::ITEM_STATUS_ERROR,
                'error_code' => self::CODE_INTERRUPTED,
                'message' => self::MESSAGE_INTERRUPTED,
            ],
            ['job_id = ?' => $jobId, 'status = ?' => self::ITEM_STATUS_RUNNING]
        );
        if ((int) $converted > 0) {
            $connection->update(
                $this->resource->getMainTable(),
                $this->counterColumns($jobId),
                ['job_id = ?' => $jobId]
            );
        }
    }

    /**
     * @param string $jobId
     * @return void
     */
    public function requestCancel(string $jobId): void
    {
        $this->connection()->update(
            $this->resource->getMainTable(),
            ['cancel_requested' => 1],
            ['job_id = ?' => $jobId]
        );
    }

    /**
     * Cancels a job that no worker has claimed. One conditional UPDATE, so it never races a claim.
     *
     * @param string $jobId
     * @return bool False when the job is not (or no longer) queued.
     */
    public function cancelIfQueued(string $jobId): bool
    {
        $rows = $this->connection()->update(
            $this->resource->getMainTable(),
            ['state' => JobInterface::STATE_CANCELLED, 'finished_at' => new Expression('NOW()')],
            ['job_id = ?' => $jobId, 'state = ?' => JobInterface::STATE_QUEUED]
        );
        return (int) $rows === 1;
    }

    /**
     * Fails a job that was stored but never reached the queue. Conditional on `queued`, so it never
     * touches a job a worker has already claimed.
     *
     * @param string $jobId
     * @param string $error
     * @return bool False when the job is not (or no longer) queued.
     */
    public function failQueued(string $jobId, string $error): bool
    {
        $rows = $this->connection()->update(
            $this->resource->getMainTable(),
            [
                'state' => JobInterface::STATE_FAILED,
                'error' => $error,
                'finished_at' => new Expression('NOW()'),
            ],
            ['job_id = ?' => $jobId, 'state = ?' => JobInterface::STATE_QUEUED]
        );
        return (int) $rows === 1;
    }

    /**
     * @param string $jobId
     * @return bool
     */
    public function isCancelRequested(string $jobId): bool
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), ['cancel_requested'])
            ->where('job_id = ?', $jobId);
        return (int) $connection->fetchOne($select) === 1;
    }

    /**
     * failed / cancelled -> queued in one conditional UPDATE. False when nothing changed, or when the
     * input is gone (a job without input cannot run).
     *
     * Being one UPDATE on the job row, it waits for the row lock {@see self::markRemainingCancelled()} holds,
     * so a cancelled job is never requeued while its cleanup is still writing skipped rows.
     *
     * `heartbeat_at` marks when the job was queued again, for {@see self::secondsSinceQueued()}. Nothing else
     * reads it on a queued job: the stale cron reads running jobs only, and the claim overwrites it.
     *
     * @param string $jobId
     * @return bool
     */
    public function requeue(string $jobId): bool
    {
        $rows = $this->connection()->update(
            $this->resource->getMainTable(),
            [
                'state' => JobInterface::STATE_QUEUED,
                'run_id' => null,
                'cancel_requested' => 0,
                'error' => null,
                'finished_at' => null,
                'heartbeat_at' => new Expression('NOW()'),
            ],
            [
                'job_id = ?' => $jobId,
                'state IN (?)' => [JobInterface::STATE_FAILED, JobInterface::STATE_CANCELLED],
                'items_json IS NOT NULL',
            ]
        );
        return (int) $rows === 1;
    }

    /**
     * @param int $adminUserId
     * @param string|null $state
     * @param string|null $toolName
     * @param int $page 1-based
     * @param int $pageSize
     * @param list<string>|null $allowedToolNames Restricts the rows to these tools before paging; an empty list
     *                                            returns nothing without a query.
     * @return array{items: list<JobInterface>, total: int}
     */
    public function listForAdmin(
        int $adminUserId,
        ?string $state,
        ?string $toolName,
        int $page,
        int $pageSize,
        ?array $allowedToolNames = null
    ): array {
        if ($allowedToolNames === []) {
            return ['items' => [], 'total' => 0];
        }
        $connection = $this->connection();
        $table = $this->resource->getMainTable();
        $filters = ['admin_user_id = ?' => $adminUserId];
        if ($state !== null) {
            $filters['state = ?'] = $state;
        }
        if ($toolName !== null) {
            $filters['tool_name = ?'] = $toolName;
        }
        if ($allowedToolNames !== null) {
            $filters['tool_name IN (?)'] = $allowedToolNames;
        }

        $countSelect = $connection->select()->from($table, [new Expression('COUNT(*)')]);
        $select = $connection->select()->from($table, self::LIST_COLUMNS);
        foreach ($filters as $condition => $value) {
            $countSelect->where($condition, $value);
            $select->where($condition, $value);
        }
        $select->order(['created_at DESC', 'entity_id DESC'])->limitPage(max(1, $page), max(1, $pageSize));

        return [
            'items' => $this->hydrate($connection->fetchAll($select)),
            'total' => (int) $connection->fetchOne($countSelect),
        ];
    }

    /**
     * Seconds since a queued job was queued: since it was created, or since it was last resumed (requeue sets
     * `heartbeat_at`). Computed by the DB so PHP and DB clocks never mix. Meaningful for queued jobs only.
     *
     * @param string $jobId
     * @return int
     */
    public function secondsSinceQueued(string $jobId): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                $this->resource->getMainTable(),
                [new Expression('TIMESTAMPDIFF(SECOND, COALESCE(heartbeat_at, created_at), NOW())')]
            )
            ->where('job_id = ?', $jobId);
        return (int) $connection->fetchOne($select);
    }

    /**
     * @param string $jobId
     * @param int $page 1-based
     * @param int $pageSize
     * @return array{items: list<array{index: int, key: ?string, status: string, error_code: ?string, message: ?string, id: ?string}>, total: int}
     */
    public function getItems(string $jobId, int $page, int $pageSize): array
    {
        $connection = $this->connection();
        $table = $this->resource->getTable(self::ITEM_TABLE);
        // The in-flight marker of a running job is not a result yet.
        $select = $connection->select()
            ->from($table)
            ->where('job_id = ?', $jobId)
            ->where('status != ?', self::ITEM_STATUS_RUNNING)
            ->order('item_index ASC')
            ->limitPage(max(1, $page), max(1, $pageSize));
        $countSelect = $connection->select()
            ->from($table, [new Expression('COUNT(*)')])
            ->where('job_id = ?', $jobId)
            ->where('status != ?', self::ITEM_STATUS_RUNNING);

        $items = [];
        foreach ($connection->fetchAll($select) as $row) {
            $items[] = [
                'index' => (int) $row['item_index'],
                'key' => $this->nullableString($row['item_key'] ?? null),
                'status' => (string) ($this->nullableString($row['status'] ?? null) ?? ''),
                'error_code' => $this->nullableString($row['error_code'] ?? null),
                'message' => $this->nullableString($row['message'] ?? null),
                'id' => $this->nullableString($row['result_id'] ?? null),
            ];
        }
        return ['items' => $items, 'total' => (int) $connection->fetchOne($countSelect)];
    }

    /**
     * @param string $jobId
     * @return bool
     */
    public function hasSnapshot(string $jobId): bool
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTable(self::SNAPSHOT_TABLE), ['entity_id'])
            ->where('job_id = ?', $jobId)
            ->limit(1);
        // fetchOne() gives false without a row; entity_id is never empty.
        return (string) $connection->fetchOne($select) !== '';
    }

    /**
     * Inserts the job's snapshot once. A row already there is kept: it holds the before-state, and a later
     * export would read data the job has changed. The update list rewrites only the unique key with itself.
     *
     * @param string $jobId
     * @param string $format
     * @param string $payload
     * @return void
     */
    public function saveSnapshot(string $jobId, string $format, string $payload): void
    {
        $this->connection()->insertOnDuplicate(
            $this->resource->getTable(self::SNAPSHOT_TABLE),
            [['job_id' => $jobId, 'format' => $format, 'payload' => $payload]],
            ['job_id']
        );
    }

    /**
     * Running jobs whose heartbeat is older than the given age (a dead worker).
     *
     * @param int $olderThanSeconds
     * @return list<JobInterface>
     */
    public function findStaleRunning(int $olderThanSeconds): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), self::LIST_COLUMNS)
            ->where('state = ?', JobInterface::STATE_RUNNING)
            ->where('heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)', $olderThanSeconds);
        return $this->hydrate($connection->fetchAll($select));
    }

    /**
     * Deletes jobs created more than $days days ago in any state except `running`. Items and snapshots
     * follow through ON DELETE CASCADE.
     *
     * @param int $days
     * @return int
     */
    public function purgeOlderThan(int $days): int
    {
        return (int) $this->connection()->delete(
            $this->resource->getMainTable(),
            [
                'created_at < DATE_SUB(NOW(), INTERVAL ? DAY)' => $days,
                'state != ?' => JobInterface::STATE_RUNNING,
            ]
        );
    }

    /**
     * Cancels the open jobs of a batch of connections in one UPDATE: a queued job is cancelled outright, a running
     * one gets `cancel_requested` and stops after its current item. An OAuth connection matches admin and client,
     * a personal one matches `token_id`. The caller bounds the batch ({@see JobCanceller::CONNECTION_PAGE_SIZE}).
     *
     * @param list<JobConnection> $connections
     * @return int Jobs touched.
     */
    public function cancelOpenForConnections(array $connections): int
    {
        $match = $this->connectionsCondition($connections);
        return $match === null ? 0 : $this->cancelOpen([$match]);
    }

    /**
     * Cancels the open jobs of every connection of an OAuth client, for every admin, with the same queued and
     * running semantics as {@see self::cancelOpenForConnections()}. For client-wide revokes.
     *
     * @param int $oauthClientId
     * @return int Jobs touched.
     */
    public function cancelOpenForClient(int $oauthClientId): int
    {
        return $this->cancelOpen(['oauth_client_id = ?' => $oauthClientId]);
    }

    /**
     * The open (queued or running) jobs of a batch of connections, matched like
     * {@see self::cancelOpenForConnections()}, in one query. Read before the cancel: an empty result lets the
     * caller skip the UPDATE, and the queued ones are those whose items the caller marks after it.
     *
     * @param list<JobConnection> $connections
     * @return array<string, string> State by job id, in entity order.
     */
    public function openJobsForConnections(array $connections): array
    {
        $match = $this->connectionsCondition($connections);
        if ($match === null) {
            return [];
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), ['job_id', 'state'])
            ->where($match)
            ->where('state IN (?)', [JobInterface::STATE_QUEUED, JobInterface::STATE_RUNNING])
            ->order('entity_id ASC');
        $jobs = [];
        foreach ($connection->fetchPairs($select) as $jobId => $state) {
            if (is_scalar($state)) {
                $jobs[(string) $jobId] = (string) $state;
            }
        }
        return $jobs;
    }

    /**
     * Ids of the OAuth client's queued jobs, matched like {@see self::cancelOpenForClient()}.
     *
     * @param int $oauthClientId
     * @return list<string>
     */
    public function queuedJobIdsForClient(int $oauthClientId): array
    {
        return $this->queuedJobIds(['oauth_client_id = ?' => $oauthClientId]);
    }

    /**
     * One page of the jobs a revoke just cancelled, for the item cleanup: the given ids that are still
     * `cancelled` (a job a worker claimed in between is `running` and left out), with only the columns the
     * cleanup needs, in one query. The caller bounds the page.
     *
     * @param list<string> $jobIds
     * @return list<JobInterface>
     */
    public function cancelledJobsForCleanup(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), self::CLEANUP_COLUMNS)
            ->where('job_id IN (?)', $jobIds)
            ->where('state = ?', JobInterface::STATE_CANCELLED)
            ->order('entity_id ASC');
        return $this->hydrate($connection->fetchAll($select));
    }

    /**
     * @param array<string, int> $match
     * @return list<string> In entity order, so the cleanup pages follow the table's key.
     */
    private function queuedJobIds(array $match): array
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->resource->getMainTable(), ['job_id']);
        foreach ($match + ['state = ?' => JobInterface::STATE_QUEUED] as $condition => $value) {
            $select->where($condition, $value);
        }
        $select->order('entity_id ASC');
        $ids = [];
        foreach ($connection->fetchCol($select) as $id) {
            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /**
     * One OR-condition for a batch of connections: `(admin_user_id = a AND oauth_client_id = c)` per OAuth
     * connection and one `token_id IN (...)` for the personal ones. Every value is an int, so it is safe inline.
     *
     * @param list<JobConnection> $connections
     * @return string|null Null when there is nothing to match on.
     */
    private function connectionsCondition(array $connections): ?string
    {
        $parts = [];
        $tokenIds = [];
        foreach ($connections as $jobConnection) {
            if ($jobConnection->oauthClientId !== null && $jobConnection->adminUserId !== null) {
                $parts[] = sprintf(
                    '(admin_user_id = %d AND oauth_client_id = %d)',
                    $jobConnection->adminUserId,
                    $jobConnection->oauthClientId
                );
            } elseif ($jobConnection->tokenId !== null) {
                $tokenIds[] = $jobConnection->tokenId;
            }
        }
        if ($tokenIds !== []) {
            $parts[] = 'token_id IN (' . implode(', ', array_map('intval', $tokenIds)) . ')';
        }
        return $parts === [] ? null : implode(' OR ', $parts);
    }

    /**
     * @param array<int|string, mixed> $match Conditions as the adapter's `update()` takes them.
     * @return int Jobs touched.
     */
    private function cancelOpen(array $match): int
    {
        $isQueued = "state = '" . JobInterface::STATE_QUEUED . "'";
        // SET runs left to right in MySQL, so finished_at must be evaluated before state changes.
        return (int) $this->connection()->update(
            $this->resource->getMainTable(),
            [
                'finished_at' => new Expression("IF($isQueued, NOW(), finished_at)"),
                'state' => new Expression("IF($isQueued, '" . JobInterface::STATE_CANCELLED . "', state)"),
                'cancel_requested' => 1,
            ],
            $match + ['state IN (?)' => [JobInterface::STATE_QUEUED, JobInterface::STATE_RUNNING]]
        );
    }

    /**
     * ok / failed / skipped recomputed from the item rows. Only for writes that change many rows at once (the
     * cancel cleanup, the INTERRUPTED conversion); a single item moves them by {@see self::counterDelta()}.
     * `failed` counts every error row, INTERRUPTED included; the in-flight marker (`running`) is never counted.
     *
     * @param string $jobId
     * @return array<string, Expression>
     */
    private function counterColumns(string $jobId): array
    {
        $connection = $this->connection();
        $table = $this->resource->getTable(self::ITEM_TABLE);
        $quotedTable = $this->text($connection->quoteIdentifier($table));
        $quotedJob = $this->text($connection->quote($jobId));
        $columns = [];
        foreach (array_flip(self::COUNTER_BY_STATUS) as $column => $status) {
            $columns[$column] = new Expression(
                '(SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE job_id = ' . $quotedJob
                . ' AND status = ' . $this->text($connection->quote($status)) . ')'
            );
        }
        return $columns;
    }

    /**
     * Counter change for one item row whose status goes from $previous (null: no row) to $new: the new status's
     * counter gains one and the previous status's counter loses one; the same counter on both sides nets zero.
     * `GREATEST(col, 1) - 1` only guards the unsigned column against a drifted 0 (a strict-mode range error);
     * a correct delta never needs it.
     *
     * @param AdapterInterface $connection
     * @param string|null $previous
     * @param string $new
     * @return array<string, Expression> Empty when nothing changes.
     */
    private function counterDelta(AdapterInterface $connection, ?string $previous, string $new): array
    {
        $add = self::COUNTER_BY_STATUS[$new] ?? null;
        $subtract = $previous === null ? null : (self::COUNTER_BY_STATUS[$previous] ?? null);
        if ($add === $subtract) {
            return [];
        }
        $delta = [];
        if ($add !== null) {
            $delta[$add] = new Expression($this->text($connection->quoteIdentifier($add)) . ' + 1');
        }
        if ($subtract !== null) {
            $delta[$subtract] = new Expression(
                'GREATEST(' . $this->text($connection->quoteIdentifier($subtract)) . ', 1) - 1'
            );
        }
        return $delta;
    }

    /**
     * Status of an item row, or null when there is none. A plain read: the caller holds the job row lock that
     * every writer of this job's item rows takes first, and a locking read of a missing row would add an InnoDB
     * gap lock shared with other jobs' inserts.
     *
     * @param AdapterInterface $connection
     * @param string $jobId
     * @param int $index
     * @return string|null
     */
    private function itemStatus(AdapterInterface $connection, string $jobId, int $index): ?string
    {
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTable(self::ITEM_TABLE), ['status'])
                ->where('job_id = ?', $jobId)
                ->where('item_index = ?', $index)
        );
        return is_array($row) ? $this->nullableString($row['status'] ?? null) : null;
    }

    /**
     * Inside a transaction: refreshes the heartbeat of the owned run, which locks the job row until the commit.
     * MySQL reports 0 changed rows when NOW() equals the stored second, so a miss is confirmed with a locking read.
     *
     * @param AdapterInterface $connection
     * @param array<string, string> $owned
     * @return bool False when this run no longer owns the job.
     */
    private function lockOwnedRun(AdapterInterface $connection, array $owned): bool
    {
        $table = $this->resource->getMainTable();
        $rows = $connection->update($table, ['heartbeat_at' => new Expression('NOW()')], $owned);
        if ((int) $rows === 1) {
            return true;
        }
        $select = $connection->select()->from($table, ['entity_id']);
        foreach ($owned as $condition => $value) {
            $select->where($condition, $value);
        }
        // fetchOne() gives false for no row; entity_id is never empty.
        return (string) $connection->fetchOne($select->forUpdate(true)) !== '';
    }

    /**
     * @param array<mixed> $row
     * @return bool True for a marker left by a stopped worker, or a row already reported INTERRUPTED.
     */
    private function wasInterrupted(array $row): bool
    {
        $status = $row['status'] ?? null;
        return $status === self::ITEM_STATUS_RUNNING
            || ($status === self::ITEM_STATUS_ERROR && ($row['error_code'] ?? null) === self::CODE_INTERRUPTED);
    }

    /**
     * @param string $jobId
     * @param int $fromIndex
     * @return array<int, true> Indexes from $fromIndex on that a stopped worker may have applied.
     */
    private function interruptedIndexes(string $jobId, int $fromIndex): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTable(self::ITEM_TABLE), ['item_index'])
            ->where('job_id = ?', $jobId)
            ->where('item_index >= ?', $fromIndex)
            // One placeholder per where(): an array value would fill every placeholder with the whole list.
            ->where(
                $this->text($connection->quoteInto('status = ?', self::ITEM_STATUS_RUNNING))
                . ' OR ' . $this->text($connection->quoteInto('error_code = ?', self::CODE_INTERRUPTED))
            );
        $indexes = [];
        foreach ((array) $connection->fetchCol($select) as $index) {
            if (is_numeric($index)) {
                $indexes[(int) $index] = true;
            }
        }
        return $indexes;
    }

    /**
     * @param string $jobId
     * @param string $runId
     * @return array<string, string>
     */
    private function ownedRunWhere(string $jobId, string $runId): array
    {
        return ['job_id = ?' => $jobId, 'state = ?' => JobInterface::STATE_RUNNING, 'run_id = ?' => $runId];
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->resource->getConnection();
        if ($connection === false) {
            throw new \LogicException('No database connection for the MCP job table.');
        }
        return $connection;
    }

    /**
     * Bounded to the item table's varchar columns (item_key 255, error_code and result_id 64 characters): on
     * strict MySQL a longer value would throw and fail the item write.
     *
     * @param string $jobId
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function itemRow(string $jobId, array $row): array
    {
        return [
            'job_id' => $jobId,
            'item_index' => $row['index'],
            'item_key' => $this->bounded($row['key'] ?? null, self::ITEM_KEY_LENGTH),
            'status' => $row['status'],
            'error_code' => $this->bounded($row['error_code'] ?? null, self::ITEM_CODE_LENGTH),
            'message' => $row['message'] ?? null,
            'result_id' => $this->bounded($row['id'] ?? null, self::ITEM_CODE_LENGTH),
        ];
    }

    /**
     * @param mixed $value
     * @param int $length
     * @return string|null Null for anything that is not a scalar.
     */
    private function bounded(mixed $value, int $length): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, $length) : null;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<JobInterface>
     */
    private function hydrate(array $rows): array
    {
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[] = $this->jobFactory->create(['data' => $row]);
        }
        return $jobs;
    }

    /**
     * @param mixed $quoted Result of the adapter's quote methods.
     * @return string
     */
    private function text(mixed $quoted): string
    {
        return is_string($quoted) ? $quoted : '';
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
