<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Cache;

use Magebit\Mcp\Tool\System\Cache\TypeArgumentResolver;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TypeArgumentResolverTest extends TestCase
{
    private TypeArgumentResolver $resolver;

    protected function setUp(): void
    {
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([
            'config' => new DataObject(['id' => 'config']),
            'layout' => new DataObject(['id' => 'layout']),
            'full_page' => new DataObject(['id' => 'full_page']),
        ]);
        $this->resolver = new TypeArgumentResolver($typeList);
    }

    public function testAllReturnsEveryAvailableType(): void
    {
        self::assertSame(
            ['config', 'layout', 'full_page'],
            $this->resolver->resolve(['all' => true])
        );
    }

    public function testExplicitTypesPassThroughValidation(): void
    {
        self::assertSame(
            ['layout', 'config'],
            $this->resolver->resolve(['cache_type' => ['layout', 'config']])
        );
    }

    public function testBothArgumentsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/either "cache_type" or "all"/');
        $this->resolver->resolve(['cache_type' => ['config'], 'all' => true]);
    }

    public function testNeitherArgumentRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Provide "cache_type"/');
        $this->resolver->resolve([]);
    }

    public function testEmptyArrayRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/non-empty array/');
        $this->resolver->resolve(['cache_type' => []]);
    }

    public function testUnknownTypeRejectedWithListedNames(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Unknown cache type\(s\): bogus, missing/');
        $this->resolver->resolve(['cache_type' => ['config', 'bogus', 'missing']]);
    }

    public function testNonStringEntryRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/non-empty strings/');
        $this->resolver->resolve(['cache_type' => ['config', 42]]);
    }
}
