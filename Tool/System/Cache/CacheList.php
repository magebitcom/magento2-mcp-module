<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Cache;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.cache.list` — enumerate cache types with status and
 * invalidated flag, mirror of `bin/magento cache:status`.
 */
class CacheList implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.cache.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_cache_list';

    public function __construct(
        private readonly TypeListInterface $cacheTypeList
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
        return 'List Cache Types';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Enumerate every Magento cache type with its enabled '
            . 'status and invalidated flag. Mirror of '
            . '`bin/magento cache:status`. Optionally narrow the list '
            . 'with `cache_type` (e.g. `["config", "full_page"]`).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('cache_type', fn (ArrayBuilder $a) => $a
                ->ofStrings(fn (StringBuilder $s) => $s->minLength(1))
                ->minItems(1)
                ->description('Narrow the output to these cache type ids '
                    . '(e.g. `["config"]`).')
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
        return 'Magento_Backend::cache';
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
        $filter = $this->resolveFilter($arguments['cache_type'] ?? null);

        $invalidated = [];
        foreach ($this->cacheTypeList->getInvalidated() as $entry) {
            $invalidated[(string) $entry['id']] = true;
        }

        $rows = [];
        foreach ($this->cacheTypeList->getTypes() as $type) {
            $id = (string) $type['id'];
            if ($filter !== null && !isset($filter[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'label' => (string) $type['cache_type'],
                'description' => (string) $type['description'],
                'tags' => (string) $type['tags'],
                'status' => ((int) $type['status']) === 1 ? 'enabled' : 'disabled',
                'invalidated' => isset($invalidated[$id]),
            ];
        }

        $payload = ['cache_types' => $rows];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode cache list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'cache_type_count' => count($rows),
                'invalidated_count' => count($invalidated),
            ]
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
                __('Filter "cache_type" must be an array of strings.')
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
                __('Filter "cache_type" must contain at least one non-empty string.')
            );
        }
        return $ids;
    }
}
