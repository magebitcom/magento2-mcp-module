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
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobToolAccess;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.job.get` — state, counters and item rows of one of the caller's background jobs.
 */
class Get implements ToolInterface
{
    public const TOOL_NAME = 'system.job.get';
    public const ACL_RESOURCE = 'Magebit_Mcp::tools';
    public const NOTICE_AFTER_SECONDS = 300;
    public const NOTICE = 'No worker has picked this job up for over 5 minutes. Check that cron runs '
        . '(bin/magento cron:run) or that the magebit_mcp_job_runner consumer is started.';

    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param JobToolAccess $access
     * @param JobRepository $jobs
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly JobToolAccess $access,
        private readonly JobRepository $jobs
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
        return 'Get Background Job';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one of your background jobs: state (queued, running, completed, failed, cancelled), '
            . 'counters, the next item index, the error if any (a completed or cancelled job may carry a '
            . 'notice instead), and a page of per-item results. Use the '
            . '`job_id` returned by the bulk tool that started the job. Cancel with `system_job_cancel`, '
            . 'continue a failed or cancelled job with `system_job_resume`.';
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
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Item page, 1-based. Defaults to 1.')
            )
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(200)
                ->description('Items per page, 1 to 200. Defaults to 50.')
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
        $admin = $this->contextProvider->get()->adminUser;
        $jobId = is_string($arguments['job_id'] ?? null) ? $arguments['job_id'] : '';
        $page = is_int($arguments['page'] ?? null) ? max(1, $arguments['page']) : 1;
        $pageSize = is_int($arguments['page_size'] ?? null) ? min(200, max(1, $arguments['page_size'])) : 50;

        $job = $this->access->loadOwned($jobId, $admin);
        $this->access->assertAllowed($job, $admin);

        $items = $this->jobs->getItems($job->getJobId(), $page, $pageSize);
        $messages = $this->access->storedMessages($job);
        $payload = [
            'job_id' => $job->getJobId(),
            'tool' => $this->access->wireName($job->getToolName()),
            'state' => $job->getState(),
            'dry_run' => $job->isDryRun(),
            'total' => $job->getTotal(),
            'ok' => $job->getOk(),
            'failed' => $job->getFailed(),
            'skipped' => $job->getSkipped(),
            'next_index' => $job->getNextIndex(),
            'error' => $messages['error'],
            'created_at' => $job->getCreatedAt(),
            'started_at' => $job->getStartedAt(),
            'finished_at' => $job->getFinishedAt(),
            'items' => $items['items'],
            'items_total' => $items['total'],
            'page' => $page,
            'page_size' => $pageSize,
        ];
        if ($messages['notice'] !== null) {
            $payload['notice'] = $messages['notice'];
        } elseif ($job->getState() === JobInterface::STATE_QUEUED
            && $this->jobs->secondsSinceQueued($job->getJobId()) > self::NOTICE_AFTER_SECONDS
        ) {
            $payload['notice'] = self::NOTICE;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode job as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'job_id' => $job->getJobId(),
                'state' => $job->getState(),
                'item_count' => count($items['items']),
            ]
        );
    }
}
