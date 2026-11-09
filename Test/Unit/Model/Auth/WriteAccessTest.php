<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Auth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\WriteAccess;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class WriteAccessTest extends TestCase
{
    /**
     * @dataProvider layerProvider
     */
    public function testReportsTheClosedLayer(bool $store, bool $token, bool $allowed, ?string $reason): void
    {
        $access = new WriteAccess($store, $token);

        self::assertSame($allowed, $access->isAllowed());
        self::assertSame($reason, $access->getClosedReason());
        self::assertSame($allowed, $access->describeClosedLayer() === '');
    }

    /**
     * @return array<string, array{bool, bool, bool, string|null}>
     */
    public static function layerProvider(): array
    {
        return [
            'both open' => [true, true, true, null],
            'store closed' => [false, true, false, WriteAccess::REASON_STORE_SETTING],
            'token closed' => [true, false, false, WriteAccess::REASON_TOKEN],
            'both closed' => [false, false, false, WriteAccess::REASON_STORE_SETTING],
        ];
    }

    public function testDescriptionsNameTheLayer(): void
    {
        self::assertStringContainsString(
            'magebit_mcp/general/allow_writes',
            (new WriteAccess(false, true))->describeClosedLayer()
        );
        self::assertStringContainsString(
            'issued without write access',
            (new WriteAccess(true, false))->describeClosedLayer()
        );
    }

    public function testResolveReadsBothLayers(): void
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getAllowWrites')->willReturn(false);
        $context = new AuthenticatedContext($token, $this->createMock(User::class));

        $access = WriteAccess::resolve($config, $context);

        self::assertTrue($access->storeAllowsWrites);
        self::assertFalse($access->tokenAllowsWrites);
    }
}
