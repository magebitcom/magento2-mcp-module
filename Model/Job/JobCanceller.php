<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Throwable;

/**
 * Cancels the open background jobs of a connection whose token an admin has just revoked or deleted, or of
 * every connection of an OAuth client that was revoked as a whole.
 *
 * Only the explicit revoke and delete paths (grid, mass action, CLI) and the client-wide revoke
 * ({@see \Magebit\Mcp\Model\TokenRepository::revokeAllForClient()}) call this. OAuth refresh rotation also
 * revokes tokens but the connection lives on, so it must never reach this class.
 */
class JobCanceller
{
    /**
     * Jobs read back per query for the item cleanup. Each row carries the job's stored input (up to one request body),
     * so the page stays small: it bounds the admin request's memory, not just its query count.
     */
    public const CLEANUP_PAGE_SIZE = 50;

    /**
     * Connections matched per lookup and per cancel UPDATE, so a mass action over many tokens costs a bounded
     * number of queries and each query a bounded condition.
     */
    public const CONNECTION_PAGE_SIZE = 50;

    /**
     * @param JobRepository $jobRepository
     * @param LoggerInterface $logger
     * @param JobItemKeys $itemKeys
     */
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly LoggerInterface $logger,
        private readonly JobItemKeys $itemKeys
    ) {
    }

    /**
     * Never throws: a revoke must succeed even when the job tables are unavailable.
     *
     * @param TokenInterface $token
     * @return int Jobs cancelled or flagged for cancellation.
     */
    public function cancelForRevokedToken(TokenInterface $token): int
    {
        try {
            $connection = JobConnection::fromToken($token);
        } catch (Throwable $e) {
            $this->logger->error('MCP job cancel on token revoke failed.', ['exception' => $e]);
            return 0;
        }
        return $connection === null ? 0 : $this->cancelForConnections([$connection]);
    }

    /**
     * Cancels the open jobs of the connections of the revoked or deleted tokens, for single and mass actions.
     * Duplicates (two tokens of one OAuth connection) are matched once. Per page of
     * {@see self::CONNECTION_PAGE_SIZE} connections it runs one lookup and, only when that finds an open job,
     * one cancel UPDATE; the queued jobs it cancelled then get their item rows in pages of
     * {@see self::CLEANUP_PAGE_SIZE}. A page whose queries fail is logged and the next page still runs.
     *
     * Never throws: a revoke must succeed even when the job tables are unavailable.
     *
     * @param list<JobConnection> $connections
     * @return int Jobs cancelled or flagged for cancellation.
     */
    public function cancelForConnections(array $connections): int
    {
        $unique = [];
        foreach ($connections as $connection) {
            $unique[$connection->key()] = $connection;
        }
        $count = 0;
        $queued = [];
        foreach (array_chunk(array_values($unique), self::CONNECTION_PAGE_SIZE) as $page) {
            try {
                $open = $this->jobRepository->openJobsForConnections($page);
                if ($open === []) {
                    continue;
                }
                $count += $this->jobRepository->cancelOpenForConnections($page);
            } catch (Throwable $e) {
                $this->logger->error('MCP job cancel on token revoke failed.', ['exception' => $e]);
                continue;
            }
            foreach ($open as $jobId => $state) {
                if ($state === JobInterface::STATE_QUEUED) {
                    $queued[] = (string) $jobId;
                }
            }
        }
        $this->markCancelledItems($queued);
        return $count;
    }

    /**
     * Never throws: a client-wide revoke must succeed even when the job tables are unavailable.
     *
     * @param int $oauthClientId
     * @return int Jobs cancelled or flagged for cancellation.
     */
    public function cancelForClient(int $oauthClientId): int
    {
        try {
            $queued = $this->jobRepository->queuedJobIdsForClient($oauthClientId);
            $count = $this->jobRepository->cancelOpenForClient($oauthClientId);
        } catch (Throwable $e) {
            $this->logger->error('MCP job cancel on OAuth client revoke failed.', ['exception' => $e]);
            return 0;
        }
        $this->markCancelledItems($queued);
        return $count;
    }

    /**
     * Gives the jobs that were queued before the cancel the same skipped/CANCELLED item rows `system_job_cancel`
     * writes. A job a worker claimed in between is `running` with `cancel_requested`; the runner marks its items
     * when it stops, so it is left alone here. Marking is idempotent per item index. Never throws.
     *
     * The jobs are read back in pages of {@see self::CLEANUP_PAGE_SIZE}, one query per page, so a revoke never
     * reads them one by one; a page that cannot be read is logged and the next one still runs.
     *
     * @param list<string> $jobIds
     * @return void
     */
    private function markCancelledItems(array $jobIds): void
    {
        foreach (array_chunk($jobIds, self::CLEANUP_PAGE_SIZE) as $page) {
            try {
                $jobs = $this->jobRepository->cancelledJobsForCleanup($page);
            } catch (Throwable $e) {
                $this->logger->error('MCP job items could not be marked cancelled.', [
                    'job_ids' => $page,
                    'exception' => $e,
                ]);
                continue;
            }
            foreach ($jobs as $job) {
                $this->markJob($job);
            }
        }
    }

    /**
     * @param JobInterface $job
     * @return void
     */
    private function markJob(JobInterface $job): void
    {
        $jobId = $job->getJobId();
        try {
            if ($job->getState() !== JobInterface::STATE_CANCELLED) {
                return;
            }
            $fromIndex = $job->getNextIndex();
            $this->jobRepository->markRemainingCancelled(
                $jobId,
                $fromIndex,
                $this->itemKeys->forJob($job, $fromIndex)
            );
        } catch (Throwable $e) {
            $this->logger->error('MCP job items could not be marked cancelled.', [
                'job_id' => $jobId,
                'exception' => $e,
            ]);
        }
    }
}
