<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\ConsentLoginReturn;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsentLoginReturnTest extends TestCase
{
    private const NONCE = '0123456789abcdef0123456789abcdef';

    private BackendUrl&MockObject $backendUrl;
    private ConsentLoginReturn $decision;

    protected function setUp(): void
    {
        $this->backendUrl = $this->createMock(BackendUrl::class);
        $this->decision = new ConsentLoginReturn($this->backendUrl);
    }

    /**
     * @return void
     */
    public function testFreshLoginReturnsToConsentForSameHandoff(): void
    {
        $this->backendUrl->expects(self::once())
            ->method('getUrl')
            ->with('magebit_mcp/oauth/authorize', ['h' => self::NONCE, '_secure' => true])
            ->willReturn('https://store.test/admin/magebit_mcp/oauth/authorize/h/' . self::NONCE . '/');

        self::assertSame(
            'https://store.test/admin/magebit_mcp/oauth/authorize/h/' . self::NONCE . '/',
            $this->decision->resolve(true, false, true, self::NONCE)
        );
    }

    /**
     * @dataProvider keepMagentoResponseProvider
     * @param bool $loginSubmitted
     * @param bool $wasLoggedIn
     * @param bool $isLoggedIn
     * @param mixed $nonce
     * @return void
     */
    public function testKeepsMagentoResponse(
        bool $loginSubmitted,
        bool $wasLoggedIn,
        bool $isLoggedIn,
        mixed $nonce
    ): void {
        $this->backendUrl->expects(self::never())->method('getUrl');

        self::assertNull($this->decision->resolve($loginSubmitted, $wasLoggedIn, $isLoggedIn, $nonce));
    }

    /**
     * @return array<string, array{bool, bool, bool, mixed}>
     */
    public static function keepMagentoResponseProvider(): array
    {
        return [
            'no login form (consent submit or plain GET)' => [false, true, true, self::NONCE],
            'failed login' => [true, false, false, self::NONCE],
            'already signed in' => [true, true, true, self::NONCE],
            'missing nonce' => [true, false, true, null],
            'empty nonce' => [true, false, true, ''],
            'nonce is not hex' => [true, false, true, 'https://evil.test/'],
            'nonce too short' => [true, false, true, 'abc123'],
            'nonce is an array' => [true, false, true, [self::NONCE]],
        ];
    }
}
