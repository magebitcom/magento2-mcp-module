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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * MCP write tool `system.index.set_mode` — switch indexers between
 * `realtime` (update on save) and `scheduled` (update by cron). Mirror
 * of `bin/magento indexer:set-mode`.
 */
class SetMode implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.index.set_mode';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_index_set_mode';

    private const MODE_REALTIME = 'realtime';
    private const MODE_SCHEDULED = 'scheduled';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly IndexerIdResolver $idResolver
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
        return 'Set Indexer Mode';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Switch the given indexers between `realtime` (update on '
            . 'save) and `scheduled` (update by cron) — mirror of '
            . '`bin/magento indexer:set-mode`. Provide either '
            . '`indexer_id` (array) or `all: true`, plus a required '
            . '`mode`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('mode', fn (StringBuilder $s) => $s
                ->enum([self::MODE_REALTIME, self::MODE_SCHEDULED])
                ->description('Target mode: `realtime` (update on save) or '
                    . '`scheduled` (update by cron).')
                ->required()
            )
            ->array('indexer_id', fn (ArrayBuilder $a) => $a
                ->ofStrings(fn (StringBuilder $s) => $s->minLength(1))
                ->minItems(1)
                ->description('Indexer ids to retarget.')
            )
            ->boolean('all', fn (BooleanBuilder $b) => $b
                ->description('Apply the mode to every indexer. Mutually '
                    . 'exclusive with `indexer_id`.')
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
        return 'Magento_Indexer::changeMode';
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
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : '';
        if ($mode !== self::MODE_REALTIME && $mode !== self::MODE_SCHEDULED) {
            throw new LocalizedException(
                __('Parameter "mode" must be "realtime" or "scheduled".')
            );
        }
        $scheduled = $mode === self::MODE_SCHEDULED;

        $ids = $this->idResolver->resolve($arguments);

        $rows = [];
        foreach ($ids as $id) {
            $indexer = $this->indexerRegistry->get($id);
            $previous = $indexer->isScheduled() ? self::MODE_SCHEDULED : self::MODE_REALTIME;
            if ($previous !== $mode) {
                $indexer->setScheduled($scheduled);
            }
            $rows[] = [
                'indexer_id' => $id,
                'previous_mode' => $previous,
                'mode' => $mode,
                'changed' => $previous !== $mode,
            ];
        }

        $payload = ['mode' => $mode, 'results' => $rows];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode set-mode result as JSON.'));
        }

        $changedCount = 0;
        foreach ($rows as $row) {
            if ($row['changed'] === true) {
                $changedCount++;
            }
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'mode' => $mode,
                'requested_count' => count($ids),
                'changed_count' => $changedCount,
            ]
        );
    }
}
