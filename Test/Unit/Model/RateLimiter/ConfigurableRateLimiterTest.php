<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\RateLimiter;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\RateLimitedException;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\RateLimiter\ConfigurableRateLimiter;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigurableRateLimiterTest extends TestCase
{
    /**
     * @var ModuleConfig
     * @phpstan-var ModuleConfig&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ModuleConfig&MockObject $config;

    /**
     * @var CacheInterface
     * @phpstan-var CacheInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CacheInterface&MockObject $cache;

    /**
     * @var LoggerInterface
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    /**
     * @var ConfigurableRateLimiter
     */
    private ConfigurableRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ModuleConfig::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->limiter = new ConfigurableRateLimiter(
            $this->config,
            $this->cache,
            $this->logger
        );
    }

    public function testNoThrottleWhenDisabled(): void
    {
        $this->config->expects($this->once())
            ->method('isRateLimitingEnabled')
            ->willReturn(false);
        $this->config->expects($this->never())->method('getRateLimitRequestsPerMinute');
        $this->cache->expects($this->never())->method('load');
        $this->cache->expects($this->never())->method('save');

        $this->limiter->check(42, 'system.store.list');
    }

    public function testNoThrottleWhenLimitNonPositive(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->expects($this->once())
            ->method('getRateLimitRequestsPerMinute')
            ->willReturn(0);
        $this->cache->expects($this->never())->method('load');
        $this->cache->expects($this->never())->method('save');

        $this->limiter->check(42, 'system.store.list');
    }

    public function testIncrementsCounterUnderLimitWhenCacheMisses(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);

        $this->cache->expects($this->once())
            ->method('load')
            ->with($this->matchesRegularExpression('/^magebit_mcp_rl:42:system\.store\.list:\d+$/'))
            ->willReturn(false);

        $this->cache->expects($this->once())
            ->method('save')
            ->with(
                '1',
                $this->matchesRegularExpression('/^magebit_mcp_rl:42:system\.store\.list:\d+$/'),
                [ConfigurableRateLimiter::CACHE_TAG],
                $this->greaterThan(60)
            );

        $this->limiter->check(42, 'system.store.list');
    }

    public function testIncrementsCounterUnderLimitWhenCacheHits(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);

        $this->cache->method('load')->willReturn('5');

        $this->cache->expects($this->once())
            ->method('save')
            ->with(
                '6',
                $this->anything(),
                [ConfigurableRateLimiter::CACHE_TAG],
                $this->anything()
            );

        $this->limiter->check(42, 'system.store.list');
    }

    public function testThrowsWhenCurrentCountEqualsLimit(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);
        $this->cache->method('load')->willReturn('10');
        $this->cache->expects($this->never())->method('save');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'MCP rate limit exceeded.',
                $this->callback(static function (array $ctx): bool {
                    return ($ctx['admin_user_id'] ?? null) === 42
                        && ($ctx['tool'] ?? null) === 'system.store.list'
                        && ($ctx['limit_per_minute'] ?? null) === 10
                        && is_int($ctx['retry_after_seconds'] ?? null);
                })
            );

        $caught = null;
        try {
            $this->limiter->check(42, 'system.store.list');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RateLimitedException::class, $caught);
        $this->assertSame(10, $caught->getLimit());
        $this->assertGreaterThanOrEqual(1, $caught->getRetryAfterSeconds());
        $this->assertLessThanOrEqual(60, $caught->getRetryAfterSeconds());
        $this->assertStringContainsString('10 requests/minute', $caught->getMessage());
        $this->assertStringContainsString('system.store.list', $caught->getMessage());
    }

    public function testThrowsWhenCurrentCountExceedsLimit(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(5);
        $this->cache->method('load')->willReturn('999');
        $this->cache->expects($this->never())->method('save');

        $this->expectException(RateLimitedException::class);

        $this->limiter->check(42, 'system.store.list');
    }

    public function testKeysAreIsolatedPerAdminUser(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);

        $seen = [];
        $this->cache->method('load')
            ->willReturnCallback(function (string $key) use (&$seen): bool {
                $seen[] = $key;
                return false;
            });
        $this->cache->method('save')->willReturn(true);

        $this->limiter->check(1, 'system.store.list');
        $this->limiter->check(2, 'system.store.list');

        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertStringContainsString(':1:', $seen[0]);
        $this->assertStringContainsString(':2:', $seen[1]);
    }

    public function testKeysAreIsolatedPerTool(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);

        $seen = [];
        $this->cache->method('load')
            ->willReturnCallback(function (string $key) use (&$seen): bool {
                $seen[] = $key;
                return false;
            });
        $this->cache->method('save')->willReturn(true);

        $this->limiter->check(42, 'system.store.list');
        $this->limiter->check(42, 'system.config.get');

        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertStringContainsString(':system.store.list:', $seen[0]);
        $this->assertStringContainsString(':system.config.get:', $seen[1]);
    }

    public function testNullCacheLoadIsTreatedAsZero(): void
    {
        $this->config->method('isRateLimitingEnabled')->willReturn(true);
        $this->config->method('getRateLimitRequestsPerMinute')->willReturn(10);
        // Some cache backends return '' instead of false on miss; both must read as zero.
        $this->cache->method('load')->willReturn(false);

        $this->cache->expects($this->once())
            ->method('save')
            ->with('1', $this->anything(), $this->anything(), $this->anything());

        $this->limiter->check(42, 'system.store.list');
    }
}
