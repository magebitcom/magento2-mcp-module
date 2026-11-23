<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Item keys of a job for display on its item rows, by the executor's rule: the bulk tool's key field value as a
 * string when scalar, else null. Shared by every place that marks items skipped/CANCELLED.
 */
class JobItemKeys
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * Keys of the job's stored items from $fromIndex on, via the key field of the job's bulk tool. Empty when
     * the tool is gone or not a bulk tool; the rows are then written without keys.
     *
     * @param JobInterface $job
     * @param int $fromIndex
     * @return array<int, string|null>
     */
    public function forJob(JobInterface $job, int $fromIndex = 0): array
    {
        try {
            $tool = $this->toolRegistry->get($job->getToolName());
        } catch (NoSuchEntityException) {
            return [];
        }
        if (!$tool instanceof BulkToolAdapter) {
            return [];
        }
        return $this->fromItems($job->getItems(), $fromIndex, $tool->getBulkTool()->getItemKeyField());
    }

    /**
     * @param array<int, mixed> $items
     * @param int $fromIndex
     * @param string $keyField
     * @return array<int, string|null>
     */
    public function fromItems(array $items, int $fromIndex, string $keyField): array
    {
        $items = array_values($items);
        $keys = [];
        for ($index = max(0, $fromIndex), $count = count($items); $index < $count; $index++) {
            $item = $items[$index];
            $value = is_array($item) ? ($item[$keyField] ?? null) : null;
            $keys[$index] = is_scalar($value) ? (string) $value : null;
        }
        return $keys;
    }
}
