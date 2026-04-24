<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Indexer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface;

/**
 * Resolve the shared `indexer_id | all` argument shape used by the three
 * indexer mutation tools (reindex / reset / set_mode) into a validated
 * list of indexer ids.
 */
class IndexerIdResolver
{
    public function __construct(
        private readonly ConfigInterface $indexerConfig
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    public function resolve(array $arguments): array
    {
        $available = array_keys($this->indexerConfig->getIndexers());
        $hasIds = array_key_exists('indexer_id', $arguments) && $arguments['indexer_id'] !== null;
        $useAll = (bool) ($arguments['all'] ?? false);

        if ($hasIds && $useAll) {
            throw new LocalizedException(
                __('Provide either "indexer_id" or "all", not both.')
            );
        }
        if (!$hasIds && !$useAll) {
            throw new LocalizedException(
                __('Provide "indexer_id" (array) or "all": true.')
            );
        }
        if ($useAll) {
            return array_values(array_map('strval', $available));
        }

        $raw = $arguments['indexer_id'];
        if (!is_array($raw) || $raw === []) {
            throw new LocalizedException(
                __('Parameter "indexer_id" must be a non-empty array of strings.')
            );
        }

        $availableIndex = array_fill_keys($available, true);
        $unknown = [];
        $resolved = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new LocalizedException(
                    __('Parameter "indexer_id" entries must be non-empty strings.')
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
                __('Unknown indexer id(s): %1', implode(', ', $unknown))
            );
        }
        return array_keys($resolved);
    }
}
