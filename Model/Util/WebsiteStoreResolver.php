<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Translate website ids into the store-view ids that belong to them.
 *
 * Most Magento entities key on `store_id`, not `website_id`, so MCP list tools
 * with a `website_id` filter must expand websites into stores before querying.
 * Fails loud on unknown ids rather than silently returning an empty set.
 */
class WebsiteStoreResolver
{
    /**
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * Accepts an int/numeric string or an array of them. Any other shape raises
     * {@see LocalizedException} so the caller can report INVALID_PARAMS.
     *
     * @param mixed $websiteIds
     * @return int[]
     * @throws LocalizedException
     */
    public function resolveStoreIds(mixed $websiteIds): array
    {
        $ids = $this->normaliseIds($websiteIds);
        if ($ids === []) {
            throw new LocalizedException(
                __('Filter "website_id" requires a positive integer or array of integers.')
            );
        }

        $known = array_fill_keys($ids, false);
        $stores = [];
        foreach ($this->storeRepository->getList() as $store) {
            if (!$store instanceof StoreInterface) {
                continue;
            }
            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                continue;
            }
            $websiteId = (int) $store->getWebsiteId();
            if (!array_key_exists($websiteId, $known)) {
                continue;
            }
            $known[$websiteId] = true;
            $stores[] = $storeId;
        }

        $missing = array_keys(array_filter($known, static fn(bool $hit): bool => !$hit));
        if ($missing !== []) {
            throw new LocalizedException(
                __('Unknown website id(s): %1.', implode(', ', $missing))
            );
        }

        return array_values(array_unique($stores));
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    private function normaliseIds(mixed $raw): array
    {
        if (is_int($raw) && $raw > 0) {
            return [$raw];
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return [(int) $raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_int($entry) && $entry > 0) {
                $out[] = $entry;
                continue;
            }
            if (is_string($entry) && ctype_digit($entry) && (int) $entry > 0) {
                $out[] = (int) $entry;
            }
        }
        return array_values(array_unique($out));
    }
}
