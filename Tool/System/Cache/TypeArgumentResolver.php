<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Cache;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Resolve the shared `cache_type | all` argument shape used by the four
 * cache mutation tools (clean / flush / enable / disable) into a validated
 * list of cache type ids.
 */
class TypeArgumentResolver
{
    /**
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    public function resolve(array $arguments): array
    {
        $available = $this->availableTypes();
        $hasTypes = array_key_exists('cache_type', $arguments) && $arguments['cache_type'] !== null;
        $useAll = (bool) ($arguments['all'] ?? false);

        if ($hasTypes && $useAll) {
            throw new LocalizedException(
                __('Provide either "cache_type" or "all", not both.')
            );
        }
        if (!$hasTypes && !$useAll) {
            throw new LocalizedException(
                __('Provide "cache_type" (array) or "all": true.')
            );
        }
        if ($useAll) {
            return $available;
        }

        $raw = $arguments['cache_type'];
        if (!is_array($raw) || $raw === []) {
            throw new LocalizedException(
                __('Parameter "cache_type" must be a non-empty array of strings.')
            );
        }

        $availableIndex = array_fill_keys($available, true);
        $unknown = [];
        $resolved = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new LocalizedException(
                    __('Parameter "cache_type" entries must be non-empty strings.')
                );
            }
            if (!isset($availableIndex[$entry])) {
                $unknown[] = $entry;
                continue;
            }
            $resolved[$entry] = true;
        }
        if ($unknown !== []) {
            throw new LocalizedException(
                __('Unknown cache type(s): %1', implode(', ', $unknown))
            );
        }
        return array_keys($resolved);
    }

    /**
     * `TypeListInterface::getTypes()` is annotated as returning `\Magento\Framework\DataObject[]`
     * but in core ships an array-shaped row. The DataObject magic getter handles either form.
     *
     * @return string[]
     */
    private function availableTypes(): array
    {
        $ids = [];
        foreach ($this->cacheTypeList->getTypes() as $type) {
            /** @var \Magento\Framework\DataObject $type */
            $ids[] = (string) $type->getId();
        }
        return $ids;
    }
}
