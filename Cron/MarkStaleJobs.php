<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Cron;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Job\JobRepository;
use Throwable;

/**
 * Fails `running` jobs whose worker died (no heartbeat for 10 minutes) so they can be resumed.
 */
class MarkStaleJobs
{
    private const STALE_AFTER_SECONDS = 600;
    private const ERROR = 'Worker stopped (no heartbeat for 10 minutes); resume with system_job_resume.';

    /**
     * @param JobRepository $jobRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            $jobs = $this->jobRepository->findStaleRunning(self::STALE_AFTER_SECONDS);
        } catch (Throwable $e) {
            $this->logger->error('MCP stale job lookup failed.', ['exception' => $e]);
            return;
        }

        $marked = 0;
        foreach ($jobs as $job) {
            $runId = $job->getRunId();
            if ($runId === null) {
                $this->logger->warning(sprintf('MCP stale job %s has no run id, skipped.', $job->getJobId()));
                continue;
            }
            try {
                // Re-checks the heartbeat in the same UPDATE: a worker that reported progress since the lookup
                // keeps its run.
                if ($this->jobRepository->failStale(
                    $job->getJobId(),
                    $runId,
                    self::STALE_AFTER_SECONDS,
                    self::ERROR
                )) {
                    $marked++;
                } else {
                    $this->logger->debug(sprintf(
                        'MCP stale job %s sent a heartbeat or changed state since the lookup, left as is.',
                        $job->getJobId()
                    ));
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    sprintf('MCP could not fail stale job %s.', $job->getJobId()),
                    ['exception' => $e]
                );
            }
        }

        if ($marked > 0) {
            $this->logger->info(sprintf('MCP marked %d stale job(s) as failed.', $marked));
        }
    }
}
