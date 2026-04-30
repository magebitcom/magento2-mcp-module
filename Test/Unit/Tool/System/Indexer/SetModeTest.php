<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Indexer;

use Magebit\Mcp\Tool\System\Indexer\IndexerIdResolver;
use Magebit\Mcp\Tool\System\Indexer\SetMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SetModeTest extends TestCase
{
    /**
     * @phpstan-var IndexerRegistry&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IndexerRegistry&MockObject $registry;

    /**
     * @phpstan-var IndexerIdResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IndexerIdResolver&MockObject $resolver;

    private SetMode $tool;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(IndexerRegistry::class);
        $this->resolver = $this->createMock(IndexerIdResolver::class);
        $this->tool = new SetMode($this->registry, $this->resolver);
    }

    public function testFlipsToScheduledOnlyWhenChangeNeeded(): void
    {
        $this->resolver->method('resolve')->willReturn(['idx_a', 'idx_b']);

        $alreadyScheduled = $this->createMock(IndexerInterface::class);
        $alreadyScheduled->method('isScheduled')->willReturn(true);
        $alreadyScheduled->expects(self::never())->method('setScheduled');

        $needsFlip = $this->createMock(IndexerInterface::class);
        $needsFlip->method('isScheduled')->willReturn(false);
        $needsFlip->expects(self::once())->method('setScheduled')->with(true);

        $this->registry->method('get')->willReturnMap([
            ['idx_a', $alreadyScheduled],
            ['idx_b', $needsFlip],
        ]);

        $result = $this->tool->execute(['mode' => 'scheduled', 'all' => true]);
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $payload = json_decode($text, true);
        self::assertIsArray($payload);

        self::assertSame('scheduled', $payload['mode'] ?? null);
        $rows = $payload['results'] ?? null;
        self::assertIsArray($rows);

        $first = $rows[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(false, $first['changed'] ?? null);

        $second = $rows[1] ?? null;
        self::assertIsArray($second);
        self::assertSame(true, $second['changed'] ?? null);

        self::assertSame(1, $result->getAuditSummary()['changed_count'] ?? null);
    }

    public function testInvalidModeRejected(): void
    {
        $this->resolver->method('resolve')->willReturn(['idx_a']);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"realtime" or "scheduled"/');
        $this->tool->execute(['mode' => 'turbo', 'all' => true]);
    }
}
