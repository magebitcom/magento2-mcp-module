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
        $this->withBaseUrl('https://example.com/');

        self::assertSame(
            'https://example.com/.well-known/oauth-protected-resource',
            $this->builder->buildUrl('/.well-known/oauth-protected-resource')
        );
        self::assertSame(
            'https://example.com/foo',
            $this->builder->buildUrl('foo')
        );
    }

    public function testGetAuthorityStripsPathComponent(): void
    {
        $this->withBaseUrl('https://example.com/lv/');
        self::assertSame('https://example.com', $this->builder->getAuthority());
    }

    public function testGetAuthorityPreservesPort(): void
    {
        $this->withBaseUrl('https://example.com:8443/lv/');
        self::assertSame('https://example.com:8443', $this->builder->getAuthority());
    }

    public function testGetAuthorityFallsBackOnMalformedBaseUrl(): void
    {
        // parse_url can't yield scheme+host — never emit an empty authority.
        $this->withBaseUrl('not a url');
        self::assertSame('not a url', $this->builder->getAuthority());
    }

    /**
     * @dataProvider basePathProvider
     * @param string $baseUrl
     * @param string $expected
     * @return void
     */
    public function testGetBasePath(string $baseUrl, string $expected): void
    {
        $this->withBaseUrl($baseUrl);
        self::assertSame($expected, $this->builder->getBasePath());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function basePathProvider(): array
    {
        return [
            'root' => ['https://example.com/', ''],
            'store code' => ['https://example.com/lv/', 'lv'],
            'subfolder' => ['https://example.com/shop/', 'shop'],
        ];
    }

    public function testGetResourceUrlKeepsBasePath(): void
    {
        $this->withBaseUrl('https://example.com/lv/');
        self::assertSame('https://example.com/lv/mcp', $this->builder->getResourceUrl());
    }

    public function testProtectedResourceWellKnownUrlInsertsAfterAuthority(): void
    {
        $this->withBaseUrl('https://example.com/lv/');
        self::assertSame(
            'https://example.com/.well-known/oauth-protected-resource/lv/mcp',
            $this->builder->getProtectedResourceWellKnownUrl()
        );
    }

    public function testProtectedResourceWellKnownUrlOnCleanBaseUrl(): void
    {
        $this->withBaseUrl('https://example.com/');
        self::assertSame(
            'https://example.com/.well-known/oauth-protected-resource/mcp',
            $this->builder->getProtectedResourceWellKnownUrl()
        );
    }

    public function testAuthServerWellKnownUrlAppendsBasePath(): void
    {
        $this->withBaseUrl('https://example.com/lv/');
        self::assertSame(
            'https://example.com/.well-known/oauth-authorization-server/lv',
            $this->builder->getAuthServerWellKnownUrl()
        );
    }

    public function testAuthServerWellKnownUrlOnCleanBaseUrl(): void
    {
        $this->withBaseUrl('https://example.com/');
        self::assertSame(
            'https://example.com/.well-known/oauth-authorization-server',
            $this->builder->getAuthServerWellKnownUrl()
        );
    }

    /**
     * @param string $baseUrl
     * @return void
     */
    private function withBaseUrl(string $baseUrl): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)->willReturn($baseUrl);
        $this->storeManager->method('getStore')->willReturn($store);
    }
}
