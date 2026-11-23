<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Captures the current state of what a bulk job is about to change, before its first item runs.
 *
 * Registered in di.xml on {@see \Magebit\Mcp\Model\Job\SnapshotProviderPool} under the tool's canonical name.
 * Runs for background jobs only: not for sync calls, not for dry runs, and not when a job is resumed past its
 * first item. Rollback is not built; the snapshot is kept for the store owner.
 */
interface JobSnapshotProviderInterface
{
    /**
     * Format of the payload export() returns, stored next to it (for example "json" or "csv").
     *
     * @return string
     */
    public function getFormat(): string;

    /**
     * Throwing fails the job before any item has run; a LocalizedException message is shown to the agent.
     *
     * @param BulkToolInterface $tool
     * @param array $items The job's validated input.
     * @phpstan-param array<int, array<string, mixed>> $items
     * @return string
     */
    public function export(BulkToolInterface $tool, array $items): string;
}
