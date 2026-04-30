<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\OAuth;

use Magebit\Mcp\Model\OAuth\Scope;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;

/**
 * RFC 9728 Protected Resource Metadata document for the MCP server.
 *
 * Advertised by the `WWW-Authenticate: Bearer … resource_metadata=…` header
 * the MCP controller emits on every 401, so OAuth-aware clients can discover
 * which authorization servers issue tokens accepted at this resource.
 */
class ProtectedResourceMetadata implements HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpResponse $response
     * @param PublicUrlBuilder $urlBuilder
     */
    public function __construct(
        private readonly HttpResponse $response,
        private readonly PublicUrlBuilder $urlBuilder
    ) {
    }

    /**
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
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
        $this->response->setBody($body !== false ? $body : '{}');
        return $this->response;
    }

    /**
     * Opt out of form-key CSRF — this is a public, unauthenticated GET that
     * returns deterministic JSON metadata. No state is mutated.
     *
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
