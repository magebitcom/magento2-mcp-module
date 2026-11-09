<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\OAuth;

use Magebit\Mcp\Controller\OAuth\AuthorizationServerMetadata;
use Magebit\Mcp\Model\Http\CorsResponder;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magebit\Mcp\Model\Url\WellKnownPathParser;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthorizationServerMetadataTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private HttpResponse&MockObject $response;
    private PublicUrlBuilder&MockObject $urlBuilder;
    private CorsResponder&MockObject $corsResponder;
    private AuthorizationServerMetadata $controller;
    private string $body = '';
    private ?int $httpCode = null;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->response = $this->createMock(HttpResponse::class);
        $this->urlBuilder = $this->createMock(PublicUrlBuilder::class);
        $this->corsResponder = $this->createMock(CorsResponder::class);

        $this->response->method('setBody')->willReturnCallback(
            function (string $body): HttpResponse {
                $this->body = $body;
                return $this->response;
            }
        );
        $this->response->method('setHttpResponseCode')->willReturnCallback(
            function (int $code): HttpResponse {
                $this->httpCode = $code;
                return $this->response;
            }
        );

        $this->controller = new AuthorizationServerMetadata(
            $this->request,
            $this->response,
            $this->urlBuilder,
            $this->corsResponder,
            new WellKnownPathParser()
        );
    }

    public function testRootDiscoveryUrlAdvertisesTheBaseUrlAsIssuer(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-authorization-server');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame('https://example.com', $this->payload()['issuer']);
    }

    public function testPathScopedDiscoveryUrlAdvertisesTheMcpIssuer(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-authorization-server/mcp');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame('https://example.com/mcp', $this->payload()['issuer']);
    }

    public function testStoreBasePathSuffixResolvesToTheSameIssuer(): void
    {
        $this->withUrls('lv', 'https://example.com/lv');
        $this->withGet('/.well-known/oauth-authorization-server/lv/mcp');

        $this->controller->execute();

        self::assertSame('https://example.com/lv/mcp', $this->payload()['issuer']);
    }

    public function testOlderStorePathSuffixKeepsTheBaseUrlAsIssuer(): void
    {
        // Clients that connected before 1.3.4 derived this URL from the base URL issuer.
        $this->withUrls('lv', 'https://example.com/lv');
        $this->withGet('/.well-known/oauth-authorization-server/lv');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame('https://example.com/lv', $this->payload()['issuer']);
    }

    public function testBareMcpSuffixIsNotFoundWhenTheStoreHasABasePath(): void
    {
        // That URL would imply issuer https://example.com/mcp, which this store never advertises.
        $this->withUrls('lv', 'https://example.com/lv');
        $this->withGet('/.well-known/oauth-authorization-server/mcp');

        $this->controller->execute();

        self::assertSame(404, $this->httpCode);
    }

    public function testTrailingFormResolvesAfterMagentoStripsTheStoreCode(): void
    {
        // Magento removes a leading store code before routing, so /lv/mcp/... arrives as /mcp/...
        $this->withUrls('lv', 'https://example.com/lv');
        $this->withGet('/mcp/.well-known/oauth-authorization-server');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame('https://example.com/lv/mcp', $this->payload()['issuer']);
    }

    public function testDirectControllerRouteAdvertisesTheBaseUrlAsIssuer(): void
    {
        $this->withUrls();
        $this->withGet('/mcp/oauth/authorizationservermetadata');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame('https://example.com', $this->payload()['issuer']);
    }

    public function testOidcStylePathAdvertisesTheMcpIssuer(): void
    {
        $this->withUrls();
        $this->withGet('/mcp/.well-known/oauth-authorization-server');

        $this->controller->execute();

        self::assertSame('https://example.com/mcp', $this->payload()['issuer']);
    }

    public function testUnknownSuffixReturnsJsonNotFound(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-authorization-server/anything');

        $this->controller->execute();

        self::assertSame(404, $this->httpCode);
        self::assertSame(['error' => 'not_found'], $this->payload());
    }

    public function testEndpointsAndPkceFieldsAreUnchanged(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-authorization-server/mcp');

        $this->controller->execute();
        $payload = $this->payload();

        self::assertSame('https://example.com/mcp/oauth/authorize', $payload['authorization_endpoint']);
        self::assertSame('https://example.com/mcp/oauth/token', $payload['token_endpoint']);
        self::assertSame(['S256'], $payload['code_challenge_methods_supported']);
        self::assertTrue($payload['pkce_required']);
    }

    public function testOptionsRequestShortCircuitsToPreflight(): void
    {
        $this->request->method('getMethod')->willReturn('OPTIONS');
        $this->urlBuilder->expects(self::never())->method('getBaseUrl');
        $this->corsResponder->expects(self::once())
            ->method('emitPreflight')
            ->with($this->response, 'GET, OPTIONS')
            ->willReturn($this->response);

        self::assertSame($this->response, $this->controller->execute());
    }

    /**
     * @param string $basePath
     * @param string $baseUrl
     * @return void
     */
    private function withUrls(string $basePath = '', string $baseUrl = 'https://example.com'): void
    {
        $this->urlBuilder->method('getBasePath')->willReturn($basePath);
        $this->urlBuilder->method('getIssuerPath')->willReturn(trim($basePath . '/mcp', '/'));
        $this->urlBuilder->method('getBaseUrl')->willReturn($baseUrl);
        $this->urlBuilder->method('getIssuerUrl')->willReturn($baseUrl . '/mcp');
        $this->urlBuilder->method('getResourceUrl')->willReturn($baseUrl . '/mcp');
        $this->urlBuilder->method('buildUrl')->willReturnCallback(
            static fn (string $path): string => $baseUrl . '/' . ltrim($path, '/')
        );
    }

    /**
     * @param string $pathInfo
     * @return void
     */
    private function withGet(string $pathInfo): void
    {
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn($pathInfo);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $decoded = json_decode($this->body, true);
        self::assertIsArray($decoded, 'Response body is not a JSON object: ' . $this->body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
