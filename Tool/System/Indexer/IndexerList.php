<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Indexer;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * MCP tool `system.index.list` — enumerate indexers with status and mode.
 * Mirror of `bin/magento indexer:status` + `indexer:show-mode`.
 */
class IndexerList implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.index.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_index_list';

    public function __construct(
        private readonly ConfigInterface $indexerConfig,
        private readonly IndexerRegistry $indexerRegistry
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
        return 'List Indexers';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Enumerate every Magento indexer with its status '
            . '(`valid` / `invalid` / `working` / `suspended`), mode '
            . '(`realtime` / `scheduled`), and last-updated timestamp. '
            . 'Mirror of `bin/magento indexer:status`. Optionally narrow '
            . 'with `indexer_id` (e.g. `["catalog_product_price"]`).';
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
                ->description('Narrow the output to these indexer ids.')
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
        $filter = $this->resolveFilter($arguments['indexer_id'] ?? null);

        $rows = [];
        foreach (array_keys($this->indexerConfig->getIndexers()) as $indexerId) {
            $id = (string) $indexerId;
            if ($filter !== null && !isset($filter[$id])) {
                continue;
            }
            $indexer = $this->indexerRegistry->get($id);
            $rows[] = $this->formatIndexer($indexer);
        }

        $payload = ['indexers' => $rows];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode indexer list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: ['indexer_count' => count($rows)]
        );
    }

    /**
     * @param mixed $raw
     * @return array<string, true>|null
     * @throws LocalizedException
     */
    private function resolveFilter(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw new LocalizedException(
                __('Filter "indexer_id" must be an array of strings.')
            );
        }
        $ids = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $ids[$entry] = true;
            }
        }
        if ($ids === []) {
            throw new LocalizedException(
                __('Filter "indexer_id" must contain at least one non-empty string.')
            );
        }
        return $ids;
    }

    /**
     * @param IndexerInterface $indexer
     * @return array<string, mixed>
     */
    private function formatIndexer(IndexerInterface $indexer): array
    {
        return [
            'id' => (string) $indexer->getId(),
            'title' => (string) $indexer->getTitle(),
            'status' => (string) $indexer->getStatus(),
            'mode' => $indexer->isScheduled() ? 'scheduled' : 'realtime',
            'updated_at' => (string) $indexer->getLatestUpdated(),
        ];
    }
}
