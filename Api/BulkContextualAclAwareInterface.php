<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Per-item ACL check for bulk tools whose required Magento resource depends on the item (like {@see ContextualAclAwareInterface}, but for one item of a batch).
 */
interface BulkContextualAclAwareInterface
{
    /**
     * @param array $item
     * @phpstan-param array<string, mixed> $item
     * @return string[] ACL resource ids that must all pass; empty means no extra check.
     */
    public function getItemAclResources(array $item): array;
}
