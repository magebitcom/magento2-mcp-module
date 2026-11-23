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
 * Deletes finished background jobs older than 30 days (items and snapshots cascade).
 */
class PurgeJobs
{
    public const RETENTION_DAYS = 30;

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
            $deleted = $this->jobRepository->purgeOlderThan(self::RETENTION_DAYS);
        } catch (Throwable $e) {
            $this->logger->error('MCP job purge failed.', ['exception' => $e]);
            return;
        }
        if ($deleted > 0) {
            $this->logger->info(sprintf(
                'MCP job purge removed %d job(s) older than %d days.',
                $deleted,
                self::RETENTION_DAYS
            ));
        }
    }
}
