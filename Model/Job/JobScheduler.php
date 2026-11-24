<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Exception;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\JobSchedulerInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Throwable;

/**
 * Stores a bulk call as a queued job and publishes its id for the `magebit_mcp_job_runner` consumer.
 */
class JobScheduler implements JobSchedulerInterface
{
    public const TOPIC = 'magebit.mcp.job.run';

    /**
     * @param JobRepository $jobs
     * @param PublisherInterface $publisher
     * @param ConsumerRunnerProbe $probe
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly PublisherInterface $publisher,
        private readonly ConsumerRunnerProbe $probe,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function schedule(
        BulkToolInterface $tool,
        array $items,
        bool $dryRun,
        AuthenticatedContext $context
    ): array {
        $tokenId = $context->token->getId();
        if ($tokenId === null) {
            throw new LocalizedException(__('A background job must belong to a stored connection.'));
        }
        $items = array_values($items);
        $job = $this->jobs->create(
            $tool->getName(),
            $context->getAdminUserId(),
            $tokenId,
            $context->token->getOAuthClientId(),
            $dryRun,
            $items
        );
        $jobId = $job->getJobId();
        try {
            $this->publisher->publish(self::TOPIC, $jobId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Publishing a background job failed',
                ['job_id' => $jobId, 'error' => $e->getMessage()]
            );
            $this->jobs->failQueued($jobId, 'Could not publish to the message queue.');
            throw new LocalizedException(
                __('The background job could not be queued; try again or send fewer items.'),
                $e instanceof Exception ? $e : null
            );
        }

        $message = 'Started as background job ' . $jobId . '; follow it with system_job_get.';
        if (!$this->probe->isStartedByCron()) {
            $message .= ' This store does not start queue workers from cron, so the job runs only if a worker'
                . ' for ' . ConsumerRunnerProbe::CONSUMER . ' is started separately.';
        }
        return [
            'job_id' => $jobId,
            'state' => JobInterface::STATE_QUEUED,
            'total' => count($items),
            'sync_cap' => $tool->getSyncCap(),
            'message' => $message,
        ];
    }
}
