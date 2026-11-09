<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Plugin\Store;

use Magebit\Mcp\Model\Url\WellKnownPathParser;
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
        $this->plugin = new WellKnownRedirectExemption(new WellKnownPathParser());
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
            'authorization server oidc style' => ['/mcp/.well-known/oauth-authorization-server'],
            'authorization server oidc style with store path' => [
                '/lv/mcp/.well-known/oauth-authorization-server',
            ],
        ];
    }

    /**
     * @dataProvider unroutedPathProvider
     * @param string $pathInfo
     * @return void
     */
    public function testLeavesPathsTheRouterDoesNotServeUntouched(string $pathInfo): void
    {
        self::assertFalse($this->plugin->afterExecute($this->subject, false, [], $this->request($pathInfo)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unroutedPathProvider(): array
    {
        return [
            'unrelated page' => ['/checkout/cart'],
            'trailing form without mcp' => ['/shop/.well-known/oauth-authorization-server'],
            'trailing form for protected resource' => ['/foo/.well-known/oauth-protected-resource'],
        ];
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
