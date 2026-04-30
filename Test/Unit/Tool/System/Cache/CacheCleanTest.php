<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Cache;

use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Cache\CacheClean;
use Magebit\Mcp\Tool\System\Cache\TypeArgumentResolver;
use Magento\Framework\App\Cache\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheCleanTest extends TestCase
{
    /**
     * @phpstan-var Manager&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Manager&MockObject $manager;

    /**
     * @phpstan-var TypeArgumentResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TypeArgumentResolver&MockObject $resolver;

    private CacheClean $tool;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(Manager::class);
        $this->resolver = $this->createMock(TypeArgumentResolver::class);
        $this->tool = new CacheClean($this->manager, $this->resolver);
    }

    public function testMetadata(): void
    {
        self::assertSame('system.cache.clean', $this->tool->getName());
        self::assertSame(WriteMode::WRITE, $this->tool->getWriteMode());
        self::assertTrue($this->tool->getConfirmationRequired());
        self::assertSame('Magento_Backend::cache', $this->tool->getUnderlyingAclResource());
    }

    public function testDelegatesToCacheManagerWithResolvedTypes(): void
    {
        $args = ['cache_type' => ['config', 'layout']];
        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with($args)
            ->willReturn(['config', 'layout']);
        $this->manager->expects(self::once())
            ->method('clean')
            ->with(['config', 'layout']);

        $result = $this->tool->execute($args);
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $payload = json_decode($text, true);
        self::assertIsArray($payload);

        self::assertSame(['config', 'layout'], $payload['cleaned_types'] ?? null);
        self::assertSame(2, $result->getAuditSummary()['cleaned_count'] ?? null);
    }
}
