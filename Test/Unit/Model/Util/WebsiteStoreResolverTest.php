<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Util;

use Magebit\Mcp\Model\Util\WebsiteStoreResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebsiteStoreResolverTest extends TestCase
{
    /**
     * @var StoreRepositoryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private StoreRepositoryInterface&MockObject $storeRepository;

    protected function setUp(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([
            $this->stubStore(0, 0),
            $this->stubStore(1, 1),
            $this->stubStore(2, 1),
            $this->stubStore(3, 2),
            $this->stubStore(4, 3),
        ]);
    }

    public function testScalarWebsiteIdReturnsStoresInThatWebsite(): void
    {
        $result = $this->resolver()->resolveStoreIds(1);

        self::assertSame([1, 2], $result);
    }

    public function testArrayWebsiteIdReturnsUnionOfStores(): void
    {
        $result = $this->resolver()->resolveStoreIds([1, 3]);

        self::assertSame([1, 2, 4], $result);
    }

    public function testNumericStringWebsiteIdIsAccepted(): void
    {
        $result = $this->resolver()->resolveStoreIds('2');

        self::assertSame([3], $result);
    }

    public function testAdminWebsiteIdIsRejectedAsInvalid(): void
    {
        // Website id 0 is the admin scope; we reject it at the input gate
        // (same as any other non-positive id) rather than let it reach the
        // store-list traversal — the admin store is never what a caller
        // filtering by website actually wants.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/requires a positive integer/');

        $this->resolver()->resolveStoreIds(0);
    }

    public function testUnknownWebsiteIdThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Unknown website id\(s\): 99/');

        $this->resolver()->resolveStoreIds([1, 99]);
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/requires a positive integer/');

        $this->resolver()->resolveStoreIds([]);
    }

    public function testInvalidInputThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/requires a positive integer/');

        $this->resolver()->resolveStoreIds('not-numeric');
    }

    private function resolver(): WebsiteStoreResolver
    {
        return new WebsiteStoreResolver($this->storeRepository);
    }

    private function stubStore(int $storeId, int $websiteId): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getWebsiteId')->willReturn($websiteId);
        return $store;
    }
}
