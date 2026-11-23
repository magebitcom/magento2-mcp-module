<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magento\Framework\App\CacheInterface;
use Throwable;

/**
 * Cached count of live tokens whose literal grant misses tools their admin's role allows.
 *
 * The admin notice reads it on every backend page. {@see \Magebit\Mcp\Model\TokenRepository} clears it whenever a
 * token is issued, revoked or deleted, {@see ClientRepository::save()} clears it when an OAuth client (and so its
 * tool cap) is saved, and a cache flush clears it too. Role changes show up within the TTL.
 */
class GrantDriftCache
{
    public const CACHE_TAG = 'MAGEBIT_MCP_GRANT_DRIFT';
    public const CACHE_KEY = 'magebit_mcp_grant_drift_count';
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The cached count, or null on a miss.
     *
     * @return int|null
     */
    public function load(): ?int
    {
        // CacheInterface::load() returns false on a miss despite its string type hint.
        $raw = (string) $this->cache->load(self::CACHE_KEY);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * @param int $count
     * @return void
     */
    public function save(int $count): void
    {
        $this->cache->save((string) $count, self::CACHE_KEY, [self::CACHE_TAG], self::CACHE_TTL_SECONDS);
    }

    /**
     * Never throws: the token write that triggered it has already happened.
     *
     * @return void
     */
    public function invalidate(): void
    {
        try {
            $this->cache->clean([self::CACHE_TAG]);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not clear the cached MCP grant drift count.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
