<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Cache;

use Magebit\Mcp\Tool\System\Cache\CacheEnable;
use Magebit\Mcp\Tool\System\Cache\TypeArgumentResolver;
use Magento\Framework\App\Cache\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheEnableTest extends TestCase
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

    private CacheEnable $tool;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(Manager::class);
        $this->resolver = $this->createMock(TypeArgumentResolver::class);
        $this->tool = new CacheEnable($this->manager, $this->resolver);
    }

    public function testReportsOnlyChangedTypesFromManager(): void
    {
        $this->resolver->method('resolve')->willReturn(['config', 'layout']);
        $this->manager->expects(self::once())
            ->method('setEnabled')
            ->with(['config', 'layout'], true)
            ->willReturn(['layout']);

        $result = $this->tool->execute(['cache_type' => ['config', 'layout']]);
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $payload = json_decode($text, true);
        self::assertIsArray($payload);

        self::assertSame(['config', 'layout'], $payload['requested_types'] ?? null);
        self::assertSame(['layout'], $payload['changed_types'] ?? null);

        self::assertSame(2, $result->getAuditSummary()['requested_count'] ?? null);
        self::assertSame(1, $result->getAuditSummary()['changed_count'] ?? null);
    }
}
