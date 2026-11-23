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
use Magebit\Mcp\Api\ToolRegistryInterface;
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
 * MCP tool `system.job.list` — the caller's background jobs, newest first, without item rows.
 */
class ListJobs implements ToolInterface
{
    public const TOOL_NAME = 'system.job.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tools';

    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param JobToolAccess $access
     * @param JobRepository $jobs
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly JobToolAccess $access,
        private readonly JobRepository $jobs,
        private readonly ToolRegistryInterface $toolRegistry
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
        return 'List Background Jobs';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List your background jobs, newest first, optionally filtered by `state` or `tool_name`. '
            . 'Jobs of tools your admin role no longer allows are left out. Read one job with `system_job_get`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('state', fn (StringBuilder $s) => $s
                ->enum([
                    JobInterface::STATE_QUEUED,
                    JobInterface::STATE_RUNNING,
                    JobInterface::STATE_COMPLETED,
                    JobInterface::STATE_FAILED,
                    JobInterface::STATE_CANCELLED,
                ])
                ->description('Only jobs in this state.')
            )
            ->string('tool_name', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Only jobs of this tool, for example `catalog_product_bulk_update`.')
            )
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Page, 1-based. Defaults to 1.')
            )
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(200)
                ->description('Jobs per page, 1 to 200. Defaults to 50.')
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
        $context = $this->contextProvider->get();
        $admin = $context->adminUser;
        $state = is_string($arguments['state'] ?? null) ? $arguments['state'] : null;
        $page = is_int($arguments['page'] ?? null) ? max(1, $arguments['page']) : 1;
        $pageSize = is_int($arguments['page_size'] ?? null) ? min(200, max(1, $arguments['page_size'])) : 50;

        $toolName = null;
        if (is_string($arguments['tool_name'] ?? null) && $arguments['tool_name'] !== '') {
            // An unknown tool name matches no job; keep the raw value so the filter simply finds nothing.
            $toolName = $this->toolRegistry->getCanonicalName($arguments['tool_name']) ?? $arguments['tool_name'];
        }

        $result = $this->jobs->listForAdmin(
            $context->getAdminUserId(),
            $state,
            $toolName,
            $page,
            $pageSize,
            $this->access->allowedToolNames($admin)
        );

        $rows = [];
        foreach ($result['items'] as $job) {
            // The same split as system_job_get: an ended job's stored text is a notice, not an error.
            $messages = $this->access->storedMessages($job);
            $row = [
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
            ];
            if ($messages['notice'] !== null) {
                $row['notice'] = $messages['notice'];
            }
            $rows[] = $row;
        }
        $json = json_encode(
            ['jobs' => $rows, 'total' => $result['total'], 'page' => $page, 'page_size' => $pageSize],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode job list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: ['job_count' => count($rows), 'total' => $result['total']]
        );
    }
}
