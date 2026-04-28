<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\OAuth;

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
    public function __construct(
        private readonly HttpResponse $response,
        private readonly PublicUrlBuilder $urlBuilder
    ) {
    }

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
            'scopes_supported' => ['mcp'],
            'service_documentation' => $issuer . '/mcp',
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->response->setHttpResponseCode(200);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setHeader('Cache-Control', 'private, max-age=300', true);
        $this->response->setBody($body !== false ? $body : '{}');
        return $this->response;
    }

    /**
     * Opt out of form-key CSRF — this is a public, unauthenticated GET that
     * returns deterministic JSON metadata. No state is mutated.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        unset($request);
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        unset($request);
        return true;
    }
}
