<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Url;

use Magebit\Mcp\Model\Url\WellKnownPath;
use Magebit\Mcp\Model\Url\WellKnownPathParser;
use PHPUnit\Framework\TestCase;

class WellKnownPathParserTest extends TestCase
{
    private WellKnownPathParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WellKnownPathParser();
    }

    /**
     * @dataProvider servedPathProvider
     * @param string $pathInfo
     * @param string $document
     * @param string $resourcePath
     * @param bool $trailing
     * @return void
     */
    public function testParsesServedPaths(
        string $pathInfo,
        string $document,
        string $resourcePath,
        bool $trailing
    ): void {
        $parsed = $this->parser->parse($pathInfo);

        self::assertNotNull($parsed);
        self::assertSame($document, $parsed->document);
        self::assertSame($resourcePath, $parsed->resourcePath);
        self::assertSame($trailing, $parsed->trailing);
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function servedPathProvider(): array
    {
        return [
            'auth server root' => [
                '/.well-known/oauth-authorization-server',
                WellKnownPath::AUTHORIZATION_SERVER,
                '',
                false,
            ],
            'auth server root with trailing slash' => [
                '/.well-known/oauth-authorization-server/',
                WellKnownPath::AUTHORIZATION_SERVER,
                '',
                false,
            ],
            'auth server suffix' => [
                '/.well-known/oauth-authorization-server/mcp',
                WellKnownPath::AUTHORIZATION_SERVER,
                'mcp',
                false,
            ],
            'auth server suffix with store path' => [
                '/.well-known/oauth-authorization-server/lv/mcp',
                WellKnownPath::AUTHORIZATION_SERVER,
                'lv/mcp',
                false,
            ],
            'auth server unknown suffix still parses' => [
                '/.well-known/oauth-authorization-server/anything',
                WellKnownPath::AUTHORIZATION_SERVER,
                'anything',
                false,
            ],
            'auth server trailing form' => [
                '/mcp/.well-known/oauth-authorization-server',
                WellKnownPath::AUTHORIZATION_SERVER,
                'mcp',
                true,
            ],
            'auth server trailing form with store path' => [
                '/lv/mcp/.well-known/oauth-authorization-server',
                WellKnownPath::AUTHORIZATION_SERVER,
                'lv/mcp',
                true,
            ],
            'protected resource root' => [
                '/.well-known/oauth-protected-resource',
                WellKnownPath::PROTECTED_RESOURCE,
                '',
                false,
            ],
            'protected resource suffix' => [
                '/.well-known/oauth-protected-resource/lv/mcp',
                WellKnownPath::PROTECTED_RESOURCE,
                'lv/mcp',
                false,
            ],
        ];
    }

    /**
     * @dataProvider unservedPathProvider
     * @param string $pathInfo
     * @return void
     */
    public function testReturnsNullForPathsTheModuleDoesNotServe(string $pathInfo): void
    {
        self::assertNull($this->parser->parse($pathInfo));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unservedPathProvider(): array
    {
        return [
            'empty' => [''],
            'root' => ['/'],
            'unrelated page' => ['/checkout/cart'],
            'sibling well-known document' => ['/.well-known/openid-configuration'],
            'partial segment match' => ['/.well-known/oauth-authorization-serverx'],
            'trailing form without mcp' => ['/shop/.well-known/oauth-authorization-server'],
            'trailing form with mcp in the middle' => ['/mcp/shop/.well-known/oauth-authorization-server'],
            'trailing form for protected resource' => ['/mcp/.well-known/oauth-protected-resource'],
            'direct controller route' => ['/mcp/oauth/authorizationservermetadata'],
        ];
    }
}
