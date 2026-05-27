<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ModuleUpdate;

use Magento\Framework\App\CacheInterface;

/**
 * Persists the "outdated MCP modules" summary the cron computes and the admin banner reads.
 *
 * TTL outlives the daily cron cadence so a single missed run never flickers the banner off;
 * if the cron stops entirely the data expires within ~1.5 days. Flush via cache:clean CACHE_TAG.
 */
class UpdateSummaryStorage
{
    public const CACHE_TAG = 'MAGEBIT_MCP_MODULE_UPDATES';
    private const CACHE_KEY = 'magebit_mcp_module_updates_summary';
    private const CACHE_TTL_SECONDS = 129600;

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @param list<array{package: string, installed: string, latest: string}> $outdated
     * @return void
     */
    public function save(array $outdated): void
    {
        $this->cache->save(
            json_encode($outdated, JSON_THROW_ON_ERROR),
            self::CACHE_KEY,
            [self::CACHE_TAG],
            self::CACHE_TTL_SECONDS
        );
    }

    /**
     * @return list<array{package: string, installed: string, latest: string}>
     */
    public function load(): array
    {
        // CacheInterface::load() returns false on a miss despite its string type hint.
        $raw = (string) $this->cache->load(self::CACHE_KEY);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $summary = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $package = $entry['package'] ?? null;
            $installed = $entry['installed'] ?? null;
            $latest = $entry['latest'] ?? null;
            if (is_string($package) && is_string($installed) && is_string($latest)) {
                $summary[] = ['package' => $package, 'installed' => $installed, 'latest' => $latest];
            }
        }

        return $summary;
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->cache->remove(self::CACHE_KEY);
    }
}
