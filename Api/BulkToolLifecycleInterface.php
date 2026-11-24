<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Tool\Bulk\BatchResult;

/**
 * Optional hooks around a bulk run, for work that belongs to the whole batch (cache flush, reindex).
 */
interface BulkToolLifecycleInterface
{
    /**
     * @param array $items
     * @phpstan-param array<int, array<string, mixed>> $items
     * @param bool $dryRun
     * @return void
     */
    public function beforeBatch(array $items, bool $dryRun): void;

    /**
     * @param BatchResult $result
     * @param bool $dryRun
     * @return void
     */
    public function afterBatch(BatchResult $result, bool $dryRun): void;
}
