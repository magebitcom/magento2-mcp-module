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
 * RFC 8414 Authorization Server Metadata. PKCE-S256 is mandatory; the token
 * endpoint accepts both `client_secret_basic` and `client_secret_post`.
 */
class AuthorizationServerMetadata implements
    HttpGetActionInterface,
    HttpOptionsActionInterface,
    CsrfAwareActionInterface
{
    private const ALLOWED_METHODS = 'GET, OPTIONS';

    private const MCP_PATH = 'mcp';

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

        $issuer = $this->resolveIssuer();
        if ($issuer === null) {
            return $this->emitJson(404, '{"error":"not_found"}');
        }

        $payload = [
            'issuer' => $issuer,
            'authorization_endpoint' => $this->urlBuilder->buildUrl('/mcp/oauth/authorize'),
            'token_endpoint' => $this->urlBuilder->buildUrl('/mcp/oauth/token'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'scopes_supported' => Scope::allValues(),
            // Explicit hint — some clients short-circuit the PKCE verifier even when
            // S256 is the only advertised challenge method.
            'pkce_required' => true,
            'require_pushed_authorization_requests' => false,
            'service_documentation' => $this->urlBuilder->getResourceUrl(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->emitJson(200, $body !== false ? $body : '{}');
    }

    /**
     * The issuer must match the URL the client used, so it depends on the path.
     * Null means the path names something this server does not serve.
     *
     * @return string|null
     */
    private function resolveIssuer(): ?string
    {
        $wellKnown = $this->pathParser->parse((string) $this->request->getPathInfo());
        $path = $wellKnown === null ? '' : $wellKnown->resourcePath;

        // Root form, and the older store-path form, keep the base URL as issuer.
        if ($path === '' || $path === $this->urlBuilder->getBasePath()) {
            return $this->urlBuilder->getBaseUrl();
        }

        if ($path === $this->urlBuilder->getIssuerPath()) {
            return $this->urlBuilder->getIssuerUrl();
        }

        // Magento strips a leading store code before routing, so a trailing
        // form may arrive as plain `mcp` even when the store has a base path.
        if ($wellKnown !== null && $wellKnown->trailing && $path === self::MCP_PATH) {
            return $this->urlBuilder->getIssuerUrl();
        }

        return null;
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
