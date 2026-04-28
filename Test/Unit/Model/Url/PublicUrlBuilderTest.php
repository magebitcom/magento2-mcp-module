<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Url;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicUrlBuilderTest extends TestCase
{
    private ModuleConfig&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private PublicUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ModuleConfig::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->builder = new PublicUrlBuilder($this->config, $this->storeManager);
    }

    public function testReturnsOverrideWhenSet(): void
    {
        // Operator sets the env.php-only override (intentionally not in system.xml).
        $this->config->method('getPublicBaseUrl')
            ->willReturn('https://test.app');
        $this->storeManager->expects(self::never())->method('getStore');

        self::assertSame(
            'https://test.app',
            $this->builder->getBaseUrl()
        );
    }

    public function testFallsBackToStoreBaseUrlWhenOverrideUnset(): void
    {
        $this->config->method('getPublicBaseUrl')->willReturn(null);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://magento-demo.docker/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Trailing slash trimmed so callers can append a path with a single concatenation.
        self::assertSame('https://magento-demo.docker', $this->builder->getBaseUrl());
    }

    public function testBuildUrlConcatenatesPathOntoOverride(): void
    {
        $this->config->method('getPublicBaseUrl')->willReturn('https://example.com');

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
