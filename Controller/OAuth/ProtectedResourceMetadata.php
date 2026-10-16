<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\OAuth;

use Magebit\Mcp\Model\Http\CorsResponder;
use Magebit\Mcp\Model\OAuth\Scope;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magebit\Mcp\Model\Url\WellKnownPathParser;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;

/**
 * RFC 9728 Protected Resource Metadata. Advertised by the `WWW-Authenticate`
 * header the MCP controller emits on every 401.
 */
class ProtectedResourceMetadata implements
    HttpGetActionInterface,
    HttpOptionsActionInterface,
    CsrfAwareActionInterface
{
    private const ALLOWED_METHODS = 'GET, OPTIONS';

    /**
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param PublicUrlBuilder $urlBuilder
     * @param CorsResponder $corsResponder
     * @param WellKnownPathParser $pathParser
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly PublicUrlBuilder $urlBuilder,
        private readonly CorsResponder $corsResponder,
        private readonly WellKnownPathParser $pathParser
    ) {
    }

    /**
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
        if (strtoupper((string) $this->request->getMethod()) === 'OPTIONS') {
            return $this->corsResponder->emitPreflight($this->response, self::ALLOWED_METHODS);
        }

        if (!$this->isServedPath()) {
            return $this->emitJson(404, '{"error":"not_found"}');
        }

        $resourceUrl = $this->urlBuilder->getResourceUrl();

        $payload = [
            'resource' => $resourceUrl,
            'authorization_servers' => [$this->urlBuilder->getIssuerUrl()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => Scope::allValues(),
            'resource_documentation' => $resourceUrl,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->emitJson(200, $body !== false ? $body : '{}');
    }

    /**
     * Only the root form and the /mcp form describe this resource.
     *
     * @return bool
     */
    private function isServedPath(): bool
    {
        $wellKnown = $this->pathParser->parse((string) $this->request->getPathInfo());
        $path = $wellKnown === null ? '' : $wellKnown->resourcePath;

        return $path === '' || $path === $this->urlBuilder->getIssuerPath();
    }

    /**
     * @param int $code
     * @param string $body
     * @return ResponseInterface
     */
    private function emitJson(int $code, string $body): ResponseInterface
    {
        $this->response->setHttpResponseCode($code);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, max-age=0', true);
        $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
        $this->response->setBody($body);

        return $this->response;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        unset($request);
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        unset($request);
        return true;
    }
}
