<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;

/**
 * MCP tool `system.module.list` — which Magento modules are installed and which
 * of them are switched on.
 *
 * The common use is capability detection: whether Multi-Source Inventory is
 * active decides which stock tools apply, and that is not otherwise visible
 * over MCP.
 */
class ModuleList implements ToolInterface
{
    public const TOOL_NAME = 'system.module.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_module_list';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ALL = 'all';

    private const MAX_PAGE_SIZE = 1000;
    private const DEFAULT_PAGE_SIZE = 300;

    /**
     * @param ModuleListInterface $moduleList
     * @param FullModuleList $fullModuleList
     * @param PackageInfo $packageInfo
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly FullModuleList $fullModuleList,
        private readonly PackageInfo $packageInfo
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
        return 'List Modules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Magento modules installed on this store and whether each '
            . 'one is enabled. Use it to check what the store actually supports '
            . 'before choosing a tool — for example, filter by `Magento_Inventory` '
            . 'to see whether Multi-Source Inventory is active, which decides '
            . 'whether stock is managed through `inventory.source_item.set` or the '
            . 'legacy `catalog.product.stock.set`. Defaults to enabled modules; '
            . 'pass `status` to include disabled ones. `name_like` is a '
            . 'case-insensitive substring match on the module name.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('status', fn (StringBuilder $s) => $s
                ->enum([self::STATUS_ENABLED, self::STATUS_DISABLED, self::STATUS_ALL])
                ->description('Which modules to return. Defaults to enabled.'))
            ->string('name_like', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(255)
                ->description('Case-insensitive substring of the module name, e.g. Magento_Inventory.'))
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('1-based page number.'))
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE)
                ->description(sprintf('Rows per page (capped at %d).', self::MAX_PAGE_SIZE)))
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
        $status = $this->status($arguments);
        $enabled = $this->enabledNames();

        $names = $status === self::STATUS_ENABLED
            ? array_values($enabled)
            : array_values($this->fullModuleList->getNames());

        $names = $this->applyNameFilter($names, $arguments);
        if ($status === self::STATUS_DISABLED) {
            $lookup = array_flip($enabled);
            $names = array_values(array_filter($names, static fn (string $n): bool => !isset($lookup[$n])));
        }

        sort($names);

        $totalCount = count($names);
        $page = isset($arguments['page']) && is_numeric($arguments['page'])
            ? max(1, (int) $arguments['page'])
            : 1;
        $pageSize = isset($arguments['page_size']) && is_numeric($arguments['page_size'])
            ? min(self::MAX_PAGE_SIZE, max(1, (int) $arguments['page_size']))
            : self::DEFAULT_PAGE_SIZE;

        $enabledLookup = array_flip($enabled);
        $items = [];
        foreach (array_slice($names, ($page - 1) * $pageSize, $pageSize) as $name) {
            $items[] = $this->describe($name, isset($enabledLookup[$name]));
        }

        $payload = [
            'total_count' => $totalCount,
            'enabled_count' => count($enabled),
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $items,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode module list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'status' => $status,
                'total_count' => $totalCount,
                'returned' => count($items),
            ]
        );
    }

    /**
     * @param string $name
     * @param bool $isEnabled
     * @return array<string, mixed>
     */
    private function describe(string $name, bool $isEnabled): array
    {
        $package = $this->packageInfo->getPackageName($name);
        $version = $package !== '' ? $this->packageInfo->getVersion($name) : '';

        return [
            'name' => $name,
            'enabled' => $isEnabled,
            'package' => $package !== '' ? $package : null,
            'version' => $version !== '' ? $version : null,
        ];
    }

    /**
     * @return string[]
     */
    private function enabledNames(): array
    {
        return array_values($this->moduleList->getNames());
    }

    /**
     * @param string[] $names
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return string[]
     */
    private function applyNameFilter(array $names, array $arguments): array
    {
        $needle = $arguments['name_like'] ?? null;
        if (!is_string($needle) || $needle === '') {
            return $names;
        }

        $needle = strtolower($needle);

        return array_values(array_filter(
            $names,
            static fn (string $n): bool => str_contains(strtolower($n), $needle)
        ));
    }

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return string
     */
    private function status(array $arguments): string
    {
        $status = $arguments['status'] ?? self::STATUS_ENABLED;

        return is_string($status) && in_array(
            $status,
            [self::STATUS_ENABLED, self::STATUS_DISABLED, self::STATUS_ALL],
            true
        ) ? $status : self::STATUS_ENABLED;
    }
}
