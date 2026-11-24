<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\JobSchedulerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;

/**
 * Presents a {@see BulkToolInterface} as a plain tool, so tools/list, tools/call, the consent tree and the CLI
 * handle it like any other. Runs small batches inline and hands larger ones to the job scheduler. It deliberately
 * does not implement ContextualAclAwareInterface: per-item ACL lives in the executor.
 */
class BulkToolAdapter implements ToolInterface, UnderlyingAclAwareInterface, AvailabilityAwareInterface
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param BulkToolInterface $tool
     * @param BulkSchemaFactory $schemaFactory
     * @param BulkExecutor $executor
     * @param JobSchedulerInterface $scheduler
     * @param AuthenticatedContextProvider $contextProvider
     */
    public function __construct(
        private readonly BulkToolInterface $tool,
        private readonly BulkSchemaFactory $schemaFactory,
        private readonly BulkExecutor $executor,
        private readonly JobSchedulerInterface $scheduler,
        private readonly AuthenticatedContextProvider $contextProvider
    ) {
    }

    /**
     * @return BulkToolInterface
     */
    public function getBulkTool(): BulkToolInterface
    {
        return $this->tool;
    }

    /**
     * @return bool
     */
    public function isAsyncCapable(): bool
    {
        return $this->tool->getAsyncCeiling() > $this->tool->getSyncCap();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->tool->getName();
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->tool->getTitle();
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return $this->schemaFactory->description($this->tool);
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return $this->tool->getAclResource();
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return $this->tool->getWriteMode();
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return $this->tool->getConfirmationRequired();
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): string
    {
        return $this->tool->getUnderlyingAclResource();
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->tool instanceof AvailabilityAwareInterface ? $this->tool->isAvailable() : true;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->schemaFactory->inputSchema($this->tool);
    }

    /**
     * Output schema advertised in tools/list.
     *
     * @return array<string, mixed>
     */
    public function getOutputSchema(): array
    {
        return $this->schemaFactory->outputSchema();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        /** @var array<int, array<string, mixed>> $items Validated against the input schema before execute(). */
        $items = array_values((array) ($arguments['items'] ?? []));
        $dryRun = (bool) ($arguments['dry_run'] ?? false);
        $count = count($items);
        $context = $this->contextProvider->get();

        if ($count > $this->tool->getSyncCap()) {
            $job = $this->scheduler->schedule($this->tool, $items, $dryRun, $context);
            return $this->result($job, false, ['job_id' => $job['job_id'], 'item_count' => $count]);
        }

        // Fills only for a tool that gives per-item audit detail; otherwise it adds nothing to the summary.
        $itemAudit = new ItemAuditCollector();
        $batch = $this->executor->run(
            $this->tool,
            $items,
            $dryRun,
            $context->adminUser,
            auditCollector: $itemAudit
        );
        return $this->result(
            $batch->toEnvelope($dryRun),
            $batch->allFailed(),
            $batch->summary() + ['item_count' => $count] + $itemAudit->toSummary()
        );
    }

    /**
     * @param array<string, mixed> $envelope
     * @param bool $isError
     * @param array<string, mixed> $auditSummary
     * @return ToolResult
     */
    private function result(array $envelope, bool $isError, array $auditSummary): ToolResult
    {
        return new ToolResult(
            [['type' => 'text', 'text' => json_encode($envelope, self::JSON_FLAGS)]],
            $isError,
            $auditSummary,
            $envelope
        );
    }
}
