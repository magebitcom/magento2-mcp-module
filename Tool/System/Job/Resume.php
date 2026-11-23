<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Job;

use Exception;
use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobScheduler;
use Magebit\Mcp\Model\Job\JobToolAccess;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use Throwable;

/**
 * MCP write tool `system.job.resume` — queues a failed or cancelled job again; it continues from its next item.
 */
class Resume implements ToolInterface
{
    public const TOOL_NAME = 'system.job.resume';
    public const ACL_RESOURCE = 'Magebit_Mcp::tools';

    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param JobToolAccess $access
     * @param JobRepository $jobs
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly JobToolAccess $access,
        private readonly JobRepository $jobs,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
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
        return 'Resume Background Job';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Queue a failed or cancelled background job again; it continues from its next item without '
            . 'repeating finished ones. Only failed or cancelled jobs can be resumed. Follow it with '
            . '`system_job_get`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('job_id', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->required()
                ->description('Job id of a failed or cancelled job.')
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
        return WriteMode::WRITE;
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
        $admin = $this->contextProvider->get()->adminUser;
        $jobId = is_string($arguments['job_id'] ?? null) ? $arguments['job_id'] : '';

        $job = $this->access->loadOwned($jobId, $admin);
        $this->access->assertAllowed($job, $admin);

        if (!in_array($job->getState(), [JobInterface::STATE_FAILED, JobInterface::STATE_CANCELLED], true)) {
            throw new LocalizedException(__('Only failed or cancelled jobs can be resumed.'));
        }
        // Only a row written without stored input (an older release) gets here without it: items_json is cleared
        // on completed only, which is refused above.
        if ($job->getItems() === []) {
            throw new LocalizedException(__(
                'Job %1 cannot be resumed because its input is no longer stored; '
                . 'send the remaining items in a new call.',
                $jobId
            ));
        }
        if (!$this->jobs->requeue($jobId)) {
            throw new LocalizedException($this->describeLostRequeue($jobId));
        }
        try {
            $this->publisher->publish(JobScheduler::TOPIC, $jobId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Publishing a resumed background job failed',
                ['job_id' => $jobId, 'error' => $e->getMessage()]
            );
            $this->jobs->failQueued($jobId, 'Could not publish to the message queue.');
            throw new LocalizedException(
                __('The background job could not be queued; try again.'),
                $e instanceof Exception ? $e : null
            );
        }

        return ToolResult::text(
            (string) __(
                'Job %1 was queued again and continues from item %2; follow it with system_job_get.',
                $jobId,
                $job->getNextIndex()
            ),
            ['job_id' => $jobId, 'next_index' => $job->getNextIndex()]
        );
    }

    /**
     * The conditional requeue changed nothing: the job left `failed` / `cancelled` between the read above and
     * the update, most often because a concurrent resume queued it first. The input is still there, so the
     * agent is sent to follow the job rather than to send the items again.
     *
     * @param string $jobId
     * @return Phrase
     */
    private function describeLostRequeue(string $jobId): Phrase
    {
        try {
            $state = $this->jobs->getByJobId($jobId)->getState();
        } catch (NoSuchEntityException) {
            $state = null;
        }
        if (in_array($state, [JobInterface::STATE_QUEUED, JobInterface::STATE_RUNNING], true)) {
            return __('Job %1 is already queued or running again; follow it with system_job_get.', $jobId);
        }
        return __('Job %1 changed state while it was being resumed; check it with system_job_get.', $jobId);
    }
}
