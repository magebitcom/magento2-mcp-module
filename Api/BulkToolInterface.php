<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Tool\Bulk\ItemResult;

/**
 * A tool that processes a list of items, each with its own result row. The bulk runner owns the envelope, the sync/async split, dry-run and per-item error handling.
 */
interface BulkToolInterface extends ToolDescriptorInterface, UnderlyingAclAwareInterface
{
    /**
     * JSON Schema of one item.
     *
     * @return array<string, mixed>
     */
    public function getItemSchema(): array;

    /**
     * Name of the item field that identifies it in result rows (for example "sku").
     */
    public function getItemKeyField(): string;

    /**
     * Largest batch that runs synchronously.
     */
    public function getSyncCap(): int;

    /**
     * Largest batch accepted at all (runs as a background job above the sync cap).
     */
    public function getAsyncCeiling(): int;

    /**
     * Bulk tools always wrap a Magento resource, so the underlying ACL resource is mandatory.
     */
    public function getUnderlyingAclResource(): string;

    /**
     * @param array $item Already validated against getItemSchema().
     * @phpstan-param array<string, mixed> $item
     * @param bool $dryRun
     * @return ItemResult
     */
    public function processItem(array $item, bool $dryRun): ItemResult;
}
