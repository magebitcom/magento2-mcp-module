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

class ConfigurableRateLimiter implements RateLimiterInterface
{
    public const CACHE_TAG = 'MAGEBIT_MCP_RATE_LIMIT';
    private const CACHE_TTL_SECONDS = 90;

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

        // Cache returns string on hit, false on miss; (int) false === 0.
        $current = (int) $this->cache->load($key);

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
     * Tool names are already constrained by ToolRegistry; no escaping needed.
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
