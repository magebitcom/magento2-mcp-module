<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Indexer;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Throwable;

/**
 * MCP write tool `system.index.reset` — invalidate indexer state so a
 * subsequent cron run rebuilds it. Mirror of
 * `bin/magento indexer:reset`.
 */
class Reset implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.index.reset';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_index_reset';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly IndexerIdResolver $idResolver,
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
        return 'Reset Indexer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Invalidate the given indexers so a subsequent cron run '
            . 'rebuilds them — mirror of `bin/magento indexer:reset`. '
            . 'Cheap operation: it just flips the state row to '
            . '`invalid`. Per-indexer exceptions are reported in the '
            . 'result body. Provide either `indexer_id` (array) or '
            . '`all: true`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('indexer_id', fn (ArrayBuilder $a) => $a
                ->ofStrings(fn (StringBuilder $s) => $s->minLength(1))
                ->minItems(1)
                ->description('Indexer ids to reset.')
            )
            ->boolean('all', fn (BooleanBuilder $b) => $b
                ->description('Reset every indexer. Mutually exclusive '
                    . 'with `indexer_id`.')
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
    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_Indexer::invalidate';
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
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $ids = $this->idResolver->resolve($arguments);

        $rows = [];
        $successCount = 0;
        $failureCount = 0;
        foreach ($ids as $id) {
            $indexer = $this->indexerRegistry->get($id);
            try {
                $indexer->invalidate();
                $rows[] = ['indexer_id' => $id, 'success' => true, 'error' => null];
                $successCount++;
            } catch (Throwable $e) {
                $this->logger->error(sprintf('invalidate failed for %s', $id), ['exception' => $e]);
                $rows[] = [
                    'indexer_id' => $id,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }

        $payload = ['results' => $rows];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode reset result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            isError: $failureCount > 0,
            auditSummary: [
                'requested_count' => count($ids),
                'success_count' => $successCount,
                'failure_count' => $failureCount,
            ]
        );
    }
}
