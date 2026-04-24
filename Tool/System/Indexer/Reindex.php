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
 * MCP write tool `system.index.reindex` — full reindex for the given
 * indexers. Mirror of `bin/magento indexer:reindex`. Per-indexer
 * exceptions are captured into the result body so a single broken
 * indexer doesn't abort the whole call.
 */
class Reindex implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.index.reindex';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_index_reindex';

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
        return 'Reindex';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Run a full reindex for the given indexers — mirror of '
            . '`bin/magento indexer:reindex`. Heavy operation; can take '
            . 'minutes on large catalogs. Each indexer is wrapped in '
            . 'try/catch — partial failures are reported per id rather '
            . 'than aborting the whole call. Provide either `indexer_id` '
            . '(array) or `all: true`.';
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
                ->description('Indexer ids to rebuild.')
            )
            ->boolean('all', fn (BooleanBuilder $b) => $b
                ->description('Reindex every indexer. Mutually exclusive '
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
        return 'Magento_Indexer::index';
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
                $indexer->reindexAll();
                $rows[] = ['indexer_id' => $id, 'success' => true, 'error' => null];
                $successCount++;
            } catch (Throwable $e) {
                $this->logger->error(sprintf('reindexAll failed for %s', $id), ['exception' => $e]);
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
            throw new LocalizedException(__('Failed to encode reindex result as JSON.'));
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
