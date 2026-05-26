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
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly PublicUrlBuilder $urlBuilder,
        private readonly CorsResponder $corsResponder
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

        $baseUrl = $this->urlBuilder->getBaseUrl();
        $resourceUrl = $baseUrl . '/mcp';

        $payload = [
            'resource' => $resourceUrl,
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => Scope::allValues(),
            'resource_documentation' => $resourceUrl,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->response->setHttpResponseCode(200);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, max-age=0', true);
        $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
        $this->response->setBody($body !== false ? $body : '{}');
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
