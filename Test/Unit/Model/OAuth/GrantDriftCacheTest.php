<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\OAuth\GrantDriftCache;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GrantDriftCacheTest extends TestCase
{
    /**
     * @phpstan-var CacheInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CacheInterface&MockObject $cache;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private GrantDriftCache $grantDriftCache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->grantDriftCache = new GrantDriftCache($this->cache, $this->logger);
    }

    /**
     * @return void
     */
    public function testLoadReturnsNullOnMiss(): void
    {
        $this->cache->method('load')->with(GrantDriftCache::CACHE_KEY)->willReturn(false);

        self::assertNull($this->grantDriftCache->load());
    }

    /**
     * @return void
     */
    public function testLoadReturnsTheCachedCount(): void
    {
        $this->cache->method('load')->with(GrantDriftCache::CACHE_KEY)->willReturn('3');

        self::assertSame(3, $this->grantDriftCache->load());
    }

    /**
     * @return void
     */
    public function testLoadTreatsGarbageAsMiss(): void
    {
        $this->cache->method('load')->willReturn('three');

        self::assertNull($this->grantDriftCache->load());
    }

    /**
     * @return void
     */
    public function testSaveStoresTheCountForAnHourUnderTheModuleTag(): void
    {
        $this->cache->expects(self::once())->method('save')
            ->with('4', GrantDriftCache::CACHE_KEY, [GrantDriftCache::CACHE_TAG], 3600)
            ->willReturn(true);

        $this->grantDriftCache->save(4);
    }

    /**
     * @return void
     */
    public function testInvalidateCleansTheTag(): void
    {
        $this->cache->expects(self::once())->method('clean')->with([GrantDriftCache::CACHE_TAG])->willReturn(true);

        $this->grantDriftCache->invalidate();
    }

    /**
     * A cache outage must not fail the token write that triggered the invalidation.
     *
     * @return void
     */
    public function testInvalidateNeverThrows(): void
    {
        $this->cache->method('clean')->willThrowException(new RuntimeException('cache down'));
        $this->logger->expects(self::once())->method('warning');

        $this->grantDriftCache->invalidate();
    }
}
