<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;

/**
 * Queues a bulk batch that is above the tool's sync cap as a background job.
 */
interface JobSchedulerInterface
{
    /**
     * @param BulkToolInterface $tool
     * @param array $items Already validated against the tool's item schema.
     * @phpstan-param array<int, array<string, mixed>> $items
     * @param bool $dryRun
     * @param AuthenticatedContext $context
     * @return array{job_id: string, state: string, total: int, sync_cap: int, message: string}
     */
    public function schedule(
        BulkToolInterface $tool,
        array $items,
        bool $dryRun,
        AuthenticatedContext $context
    ): array;
}
