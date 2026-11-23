<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobToolAccess;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `system.job.cancel` — cancels a queued job at once, or asks a running one to stop after the
 * current item.
 */
class Cancel implements ToolInterface
{
    public const TOOL_NAME = 'system.job.cancel';
    public const ACL_RESOURCE = 'Magebit_Mcp::tools';

    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param JobToolAccess $access
     * @param JobRepository $jobs
     * @param JobItemKeys $itemKeys
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly JobToolAccess $access,
        private readonly JobRepository $jobs,
        private readonly JobItemKeys $itemKeys
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
        return 'Cancel Background Job';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cancel one of your background jobs. A queued job is cancelled at once and its items are marked '
            . 'skipped; a running job stops after the current item. Finished jobs cannot be cancelled. Follow '
            . 'the job with `system_job_get`; a cancelled job can be continued with `system_job_resume`.';
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
                ->description('Job id returned when the job was started.')
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

        $state = $job->getState();
        if ($state === JobInterface::STATE_QUEUED && $this->jobs->cancelIfQueued($jobId)) {
            $fromIndex = $job->getNextIndex();
            $this->jobs->markRemainingCancelled($jobId, $fromIndex, $this->itemKeys->forJob($job, $fromIndex));
            return ToolResult::text(
                (string) __('Job %1 was cancelled before it started.', $jobId),
                ['job_id' => $jobId, 'outcome' => 'cancelled']
            );
        }
        if ($state === JobInterface::STATE_QUEUED || $state === JobInterface::STATE_RUNNING) {
            // A worker may have claimed the queued job meanwhile; it then stops after the current item.
            $this->jobs->requestCancel($jobId);
            return ToolResult::text(
                (string) __(
                    'Job %1 will stop after the current item; follow it with system_job_get.',
                    $jobId
                ),
                ['job_id' => $jobId, 'outcome' => 'cancel_requested']
            );
        }
        throw new LocalizedException(__('Job %1 has already finished (%2).', $jobId, $state));
    }
}
