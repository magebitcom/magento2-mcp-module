<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\OAuth;

use Magebit\Mcp\Controller\OAuth\ProtectedResourceMetadata;
use Magebit\Mcp\Model\Http\CorsResponder;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magebit\Mcp\Model\Url\WellKnownPathParser;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProtectedResourceMetadataTest extends TestCase
{
    private const ISSUER_URL = 'https://example.com/mcp';
    private const RESOURCE_URL = 'https://example.com/resource/mcp';

    private HttpRequest&MockObject $request;
    private HttpResponse&MockObject $response;
    private PublicUrlBuilder&MockObject $urlBuilder;
    private CorsResponder&MockObject $corsResponder;
    private ProtectedResourceMetadata $controller;
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

        $this->controller = new ProtectedResourceMetadata(
            $this->request,
            $this->response,
            $this->urlBuilder,
            $this->corsResponder,
            new WellKnownPathParser()
        );
    }

    public function testAdvertisesThePathScopedAuthorizationServerOnly(): void
    {
        $this->withUrls();
        $this->request->method('getMethod')->willReturn('GET');

        $this->controller->execute();

        // A root fallback entry would land on another app's metadata.
        self::assertSame([self::ISSUER_URL], $this->payload()['authorization_servers']);
    }

    public function testResourceIdentifierIsTheMcpEndpoint(): void
    {
        $this->withUrls();
        $this->request->method('getMethod')->willReturn('GET');

        $this->controller->execute();

        self::assertSame(self::RESOURCE_URL, $this->payload()['resource']);
    }

    public function testKeepsTheStoreBasePath(): void
    {
        $this->withUrls('https://example.com/lv/mcp');
        $this->request->method('getMethod')->willReturn('GET');

        $this->controller->execute();

        self::assertSame(['https://example.com/lv/mcp'], $this->payload()['authorization_servers']);
    }

    public function testRootDiscoveryUrlIsServed(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-protected-resource');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
        self::assertSame(self::RESOURCE_URL, $this->payload()['resource']);
    }

    public function testPathScopedDiscoveryUrlIsServed(): void
    {
        $this->withUrls('https://example.com/lv/mcp', 'https://example.com/lv/mcp', 'lv/mcp');
        $this->withGet('/.well-known/oauth-protected-resource/lv/mcp');

        $this->controller->execute();

        self::assertSame(200, $this->httpCode);
    }

    public function testUnknownSuffixReturnsJsonNotFound(): void
    {
        $this->withUrls();
        $this->withGet('/.well-known/oauth-protected-resource/anything');

        $this->controller->execute();

        self::assertSame(404, $this->httpCode);
        self::assertSame(['error' => 'not_found'], $this->payload());
    }

    public function testOptionsRequestShortCircuitsToPreflight(): void
    {
        $this->request->method('getMethod')->willReturn('OPTIONS');
        $this->urlBuilder->expects(self::never())->method('getIssuerUrl');
        $this->corsResponder->expects(self::once())
            ->method('emitPreflight')
            ->with($this->response, 'GET, OPTIONS')
            ->willReturn($this->response);

        self::assertSame($this->response, $this->controller->execute());
    }

    /**
     * The two URLs are deliberately different so each response key stays
     * pinned to the builder method that feeds it.
     *
     * @param string $issuer
     * @param string $resource
     * @param string $issuerPath
     * @return void
     */
    private function withUrls(
        string $issuer = self::ISSUER_URL,
        string $resource = self::RESOURCE_URL,
        string $issuerPath = 'mcp'
    ): void {
        $this->urlBuilder->method('getResourceUrl')->willReturn($resource);
        $this->urlBuilder->method('getIssuerUrl')->willReturn($issuer);
        $this->urlBuilder->method('getIssuerPath')->willReturn($issuerPath);
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
