<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Url;

use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicUrlBuilderTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private PublicUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->builder = new PublicUrlBuilder($this->storeManager);
    }

    public function testReturnsStoreBaseUrl(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://mcp-module.docker/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Trailing slash trimmed so callers can append a path with a single concatenation.
        self::assertSame('https://mcp-module.docker', $this->builder->getBaseUrl());
    }

    public function testBuildUrlConcatenatesPathOntoBaseUrl(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        self::assertSame(
            'https://example.com/.well-known/oauth-protected-resource',
            $this->builder->buildUrl('/.well-known/oauth-protected-resource')
        );
        self::assertSame(
            'https://example.com/foo',
            $this->builder->buildUrl('foo')
        );
    }
}
