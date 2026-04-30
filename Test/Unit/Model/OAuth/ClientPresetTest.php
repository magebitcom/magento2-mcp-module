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
use PHPUnit\Framework\TestCase;

class ClientPresetTest extends TestCase
{
    public function testRejectsBadId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ClientPreset('Has-Hyphen', 'X', 'X', []);
    }

    public function testTrimsAndDeduplicatesRedirectUris(): void
    {
        $preset = new ClientPreset(
            'good',
            'Good',
            'Good',
            ['  https://a/cb  ', '', 'https://a/cb', 'https://b/cb']
        );

        self::assertSame(
            ['https://a/cb', 'https://b/cb'],
            $preset->getRedirectUris()
        );
    }

    public function testGetters(): void
    {
        $preset = new ClientPreset('claude_web', 'Claude Web', 'Claude Web', ['https://claude.ai/cb']);
        self::assertSame('claude_web', $preset->getId());
        self::assertSame('Claude Web', $preset->getLabel());
        self::assertSame('Claude Web', $preset->getName());
        self::assertSame(['https://claude.ai/cb'], $preset->getRedirectUris());
    }
}
