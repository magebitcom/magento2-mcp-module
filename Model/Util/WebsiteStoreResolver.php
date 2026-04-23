<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Translate website ids into the store-view ids that belong to them.
 *
 * Most Magento entities are stored with a `store_id` column rather than
 * `website_id` — `sales_order.store_id`, `cms_page_store.store_id`, etc.
 * When an MCP list tool exposes a `website_id` filter it therefore has to
 * expand each website into its stores before adding the criteria. This
 * helper centralises that translation so individual tool modules don't
 * each re-fetch the full store list.
 *
 * `resolveStoreIds()` accepts either a scalar or an array of website ids
 * and returns the deduplicated list of matching `store_id`s. It throws
 * when the caller supplies a value that doesn't map to any known store —
 * failing loud is preferable to silently returning an empty set that
 * would match nothing.
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
     * Return the store-view ids belonging to the given website id(s).
     *
     * Accepts:
     *   - an int or numeric string  ⇒ one website
     *   - an array of ints / numeric strings ⇒ multiple websites
     *
     * Any other shape (object, mixed scalar, empty) raises
     * {@see LocalizedException}. The caller is then free to report
     * `INVALID_PARAMS`.
     *
     * @param mixed $websiteIds
     * @return int[] Deduplicated store ids, empty only when the resolved
     *               websites genuinely contain no stores (rare — typically
     *               a misconfigured website).
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
     * Flatten scalar-or-array website-id input into a clean int[].
     *
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
