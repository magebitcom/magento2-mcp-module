<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\RateLimiter;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Exception\RateLimitedException;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magento\Framework\App\CacheInterface;

/**
 * Fixed-window-per-minute rate limiter, admin-configurable via
 * Stores → Configuration → Magebit → MCP Server → Rate Limiting.
 *
 * Keys a counter per `(admin_user_id, tool_name, minute_epoch)` in Magento's
 * cache frontend. Multiple bearer tokens owned by the same admin share the
 * budget; different tools and different admins are isolated.
 *
 * Known caveats (documented, acceptable for v1):
 *
 * - **Minute boundary burst** — a caller that uses their full quota at second
 *   59 and immediately issues the same quota at second 00 of the next minute
 *   effectively sees 2× the configured limit in a two-second span. This is
 *   inherent to fixed-window counters; swap in a sliding-window or token-bucket
 *   implementation behind the interface if strict smoothing is required.
 *
 * - **Non-atomic increment** — {@see CacheInterface} exposes `load` and `save`
 *   but no atomic `INCR`, so two concurrent FPM workers reading the same
 *   counter can both write counter+1 when the real count should be counter+2.
 *   Under heavy concurrency this allows a small overshoot. For a soft abuse
 *   limiter this is acceptable; for hard accounting, move to a DB-backed
 *   counter with `INSERT … ON DUPLICATE KEY UPDATE counter = counter + 1`.
 */
class ConfigurableRateLimiter implements RateLimiterInterface
{
    /**
     * Cache tag applied to every counter. Operators can purge all in-flight
     * rate-limit state with `bin/magento cache:clean MAGEBIT_MCP_RATE_LIMIT`.
     */
    public const CACHE_TAG = 'MAGEBIT_MCP_RATE_LIMIT';

    /**
     * Cache TTL (seconds). Longer than the window itself so entries created
     * near the end of a minute still live long enough for their successors
     * to read the previous count during the boundary transition, then expire
     * naturally.
     */
    private const CACHE_TTL_SECONDS = 90;

    /**
     * Window length in seconds. Not configurable in v1 — "per minute" is the
     * unit exposed in the admin UI.
     */
    private const WINDOW_SECONDS = 60;

    /**
     * @param ModuleConfig $config
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(int $adminUserId, string $toolName): void
    {
        if (!$this->config->isRateLimitingEnabled()) {
            return;
        }

        $limit = $this->config->getRateLimitRequestsPerMinute();
        if ($limit <= 0) {
            return;
        }

        $now = time();
        $windowEpoch = intdiv($now, self::WINDOW_SECONDS);
        $key = $this->buildKey($adminUserId, $toolName, $windowEpoch);

        $raw = $this->cache->load($key);
        $current = is_string($raw) ? (int) $raw : 0;

        if ($current >= $limit) {
            $retryAfter = self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);
            $this->logger->info(
                'MCP rate limit exceeded.',
                [
                    'admin_user_id' => $adminUserId,
                    'tool' => $toolName,
                    'limit_per_minute' => $limit,
                    'retry_after_seconds' => $retryAfter,
                ]
            );
            throw new RateLimitedException(
                sprintf(
                    'Rate limit exceeded: %d requests/minute for "%s".',
                    $limit,
                    $toolName
                ),
                $limit,
                $retryAfter
            );
        }

        $this->cache->save(
            (string) ($current + 1),
            $key,
            [self::CACHE_TAG],
            self::CACHE_TTL_SECONDS
        );
    }

    /**
     * Build the cache key for a counter.
     *
     * Tool names are already constrained to `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`
     * by {@see \Magebit\Mcp\Model\Tool\ToolRegistry}, so no escaping is needed.
     *
     * @param int $adminUserId
     * @param string $toolName
     * @param int $windowEpoch
     * @return string
     */
    private function buildKey(int $adminUserId, string $toolName, int $windowEpoch): string
    {
        return sprintf('magebit_mcp_rl:%d:%s:%d', $adminUserId, $toolName, $windowEpoch);
    }
}
