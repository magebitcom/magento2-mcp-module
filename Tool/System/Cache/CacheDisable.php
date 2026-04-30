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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP write tool `system.cache.disable` — turn off the given cache types.
 * Mirror of `bin/magento cache:disable`.
 */
class CacheDisable implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'system.cache.disable';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_cache_disable';

    /**
     * @param Manager $cacheManager
     * @param TypeArgumentResolver $typeResolver
     */
    public function __construct(
        private readonly Manager $cacheManager,
        private readonly TypeArgumentResolver $typeResolver
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
        return 'Disable Cache Types';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Disable the given cache types — mirror of '
            . '`bin/magento cache:disable`. Returns the subset whose '
            . 'state actually changed. Provide either `cache_type` '
            . '(array of ids) or `all: true`.';
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
                ->description('Cache type ids to disable.')
            )
            ->boolean('all', fn (BooleanBuilder $b) => $b
                ->description('Disable every cache type. Mutually exclusive '
                    . 'with `cache_type`.')
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
        $types = $this->typeResolver->resolve($arguments);
        $changed = $this->cacheManager->setEnabled($types, false);
        $changedIds = array_values(array_map(static fn ($t) => (string) $t, $changed));

        $payload = [
            'requested_types' => $types,
            'changed_types' => $changedIds,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'requested_count' => count($types),
                'changed_count' => count($changedIds),
            ]
        );
    }
}
