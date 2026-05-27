<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Plugin\Store;

use Magebit\Mcp\Plugin\Store\WellKnownRedirectExemption;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\BaseUrlChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WellKnownRedirectExemptionTest extends TestCase
{
    private BaseUrlChecker&MockObject $subject;
    private WellKnownRedirectExemption $plugin;

    protected function setUp(): void
    {
        $this->subject = $this->createMock(BaseUrlChecker::class);
        $this->plugin = new WellKnownRedirectExemption();
    }

    /**
     * @dataProvider exemptPathProvider
     * @param string $pathInfo
     * @return void
     */
    public function testExemptsDiscoveryPathsFromRedirect(string $pathInfo): void
    {
        self::assertTrue($this->plugin->afterExecute($this->subject, false, [], $this->request($pathInfo)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exemptPathProvider(): array
    {
        return [
            'protected resource exact' => ['/.well-known/oauth-protected-resource'],
            'protected resource path-aware' => ['/.well-known/oauth-protected-resource/lv/mcp'],
            'authorization server exact' => ['/.well-known/oauth-authorization-server'],
            'authorization server path-aware' => ['/.well-known/oauth-authorization-server/lv'],
        ];
    }

    public function testLeavesUnrelatedPathsUntouched(): void
    {
        self::assertFalse($this->plugin->afterExecute($this->subject, false, [], $this->request('/checkout/cart')));
    }

    public function testDoesNotMatchOnPartialPrefix(): void
    {
        // A sibling well-known suffix must not be swept in by the prefix check.
        self::assertFalse(
            $this->plugin->afterExecute($this->subject, false, [], $this->request('/.well-known/openid-configuration'))
        );
    }

    public function testPassesThroughWhenAlreadyValid(): void
    {
        self::assertTrue($this->plugin->afterExecute($this->subject, true, [], $this->request('/checkout/cart')));
    }

    /**
     * @param string $pathInfo
     * @return HttpRequest&MockObject
     */
    private function request(string $pathInfo): HttpRequest&MockObject
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        return $request;
    }
}
