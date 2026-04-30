<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use InvalidArgumentException;
use Magebit\Mcp\Model\OAuth\ClientPreset;
use Magebit\Mcp\Model\OAuth\ClientPresetProvider;
use PHPUnit\Framework\TestCase;

class ClientPresetProviderTest extends TestCase
{
    public function testCustomPresetIsAlwaysFirst(): void
    {
        $claudeWeb = new ClientPreset('claude_web', 'Claude Web', 'Claude Web', ['https://claude.ai/api/mcp/auth_callback']);
        $provider = new ClientPresetProvider([$claudeWeb]);

        $all = $provider->getAll();
        self::assertCount(2, $all);
        self::assertSame('custom', $all[0]->getId());
        self::assertSame('claude_web', $all[1]->getId());
    }

    public function testDuplicateIdRejected(): void
    {
        $a = new ClientPreset('claude_web', 'A', 'A', ['https://a/cb']);
        $b = new ClientPreset('claude_web', 'B', 'B', ['https://b/cb']);

        $this->expectException(InvalidArgumentException::class);
        new ClientPresetProvider([$a, $b]);
    }

    public function testDuplicateOfReservedCustomIdRejected(): void
    {
        $shadow = new ClientPreset('custom', 'Shadow', 'Shadow', []);
        $this->expectException(InvalidArgumentException::class);
        new ClientPresetProvider([$shadow]);
    }

    public function testNonPresetEntryRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line wrong type passed deliberately */
        new ClientPresetProvider(['not-a-preset']);
    }

    public function testOrderingPreserved(): void
    {
        $a = new ClientPreset('claude_web', 'Claude Web', 'Claude Web', ['https://claude.ai/cb']);
        $b = new ClientPreset('chatgpt', 'ChatGPT', 'ChatGPT', ['https://chatgpt.com/cb']);

        $provider = new ClientPresetProvider([$a, $b]);
        $ids = array_map(static fn ($p) => $p->getId(), $provider->getAll());

        self::assertSame(['custom', 'claude_web', 'chatgpt'], $ids);
    }
}
