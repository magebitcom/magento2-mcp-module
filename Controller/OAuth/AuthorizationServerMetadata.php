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
 * RFC 8414 Authorization Server Metadata document.
 *
 * The MCP module embeds an OAuth 2.1 authorization server, so the resource
 * (this Magento install) and the issuer share the same origin. PKCE-S256 is
 * mandatory; client authentication on the token endpoint accepts both
 * `client_secret_basic` and `client_secret_post`.
 */
class AuthorizationServerMetadata implements HttpGetActionInterface, CsrfAwareActionInterface
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
        $issuer = $this->urlBuilder->getBaseUrl();

        $payload = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/mcp/oauth/authorize',
            'token_endpoint' => $issuer . '/mcp/oauth/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'scopes_supported' => Scope::allValues(),
            // Hint to OAuth-aware clients that PKCE is mandatory — without this they
            // sometimes short-circuit the verifier wiring even when S256 is the only
            // advertised challenge method.
            'pkce_required' => true,
            'require_pushed_authorization_requests' => false,
            'service_documentation' => $issuer . '/mcp',
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
