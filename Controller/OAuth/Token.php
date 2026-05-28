<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Http\CorsResponder;
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\AuthCodeRepository;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\IssuedTokenPair;
use Magebit\Mcp\Model\OAuth\OAuthErrorResponse;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RefreshTokenRotator;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * `POST /mcp/oauth/token` — OAuth 2.1 token endpoint. Implements authorization_code
 * and refresh_token grants (revoke-on-use rotation per §6.1); accepts both
 * client_secret_basic and client_secret_post client authentication.
 */
class Token implements
    HttpPostActionInterface,
    HttpGetActionInterface,
    HttpOptionsActionInterface,
    CsrfAwareActionInterface
{
    private const ALLOWED_METHODS = 'POST, OPTIONS';

    /**
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param ClientRepository $clientRepository
     * @param AuthCodeRepository $authCodeRepository
     * @param TokenHasher $tokenHasher
     * @param PkceVerifier $pkceVerifier
     * @param AccessTokenIssuer $accessTokenIssuer
     * @param RefreshTokenRotator $refreshTokenRotator
     * @param OAuthErrorResponse $errorResponse
     * @param ToolGrantResolver $toolGrantResolver
     * @param CorsResponder $corsResponder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly ClientRepository $clientRepository,
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly PkceVerifier $pkceVerifier,
        private readonly AccessTokenIssuer $accessTokenIssuer,
        private readonly RefreshTokenRotator $refreshTokenRotator,
        private readonly OAuthErrorResponse $errorResponse,
        private readonly ToolGrantResolver $toolGrantResolver,
        private readonly CorsResponder $corsResponder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
        $method = strtoupper((string) $this->request->getMethod());
        if ($method === 'OPTIONS') {
            return $this->corsResponder->emitPreflight($this->response, self::ALLOWED_METHODS);
        }
        if ($method !== 'POST') {
            $this->response->setHttpResponseCode(405);
            $this->response->setHeader('Allow', self::ALLOWED_METHODS, true);
            $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
            $this->response->setBody('');
            return $this->response;
        }

        try {
            $client = $this->authenticateClient();
            if ($client->isDisabled()) {
                // Same wording as unknown-client — don't leak disabled-vs-rotated to a leaked secret holder.
                throw new OAuthException('invalid_client', 'Unknown client.', 401);
            }
            $grantType = $this->stringParam('grant_type');

            return match ($grantType) {
                'authorization_code' => $this->handleAuthCode($client),
                'refresh_token' => $this->handleRefresh($client),
                default => throw new OAuthException(
                    'unsupported_grant_type',
                    'Only authorization_code and refresh_token are supported.'
                ),
            };
        } catch (OAuthException $e) {
            $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
            return $this->errorResponse->emit($this->response, $e);
        } catch (\Throwable $e) {
            $this->logger->error('OAuth token endpoint failed.', ['exception' => $e]);
            $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
            return $this->errorResponse->emit(
                $this->response,
                new OAuthException('server_error', 'Token endpoint error.', 500)
            );
        }
    }

    /**
     * @throws OAuthException
     */
    private function authenticateClient(): Client
    {
        $clientId = '';
        $clientSecret = '';

        $rawAuthHeader = $this->request->getHeader('Authorization');
        $authHeader = is_string($rawAuthHeader) ? $rawAuthHeader : '';
        if ($authHeader !== '' && stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);
            }
        }
        if ($clientId === '') {
            $clientId = $this->stringParam('client_id');
            $clientSecret = $this->stringParam('client_secret');
        }
        if ($clientId === '' || $clientSecret === '') {
            throw new OAuthException('invalid_client', 'Client credentials missing.', 401);
        }

        try {
            $client = $this->clientRepository->getByClientId($clientId);
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }

        $expectedHash = $client->getClientSecretHash();
        if (!hash_equals($expectedHash, $this->tokenHasher->hash($clientSecret))) {
            throw new OAuthException('invalid_client', 'Invalid client secret.', 401);
        }
        return $client;
    }

    /**
     * @param Client $client
     * @return ResponseInterface
     * @throws OAuthException
     */
    private function handleAuthCode(Client $client): ResponseInterface
    {
        $code = $this->stringParam('code');
        $codeVerifier = $this->stringParam('code_verifier');
        $redirectUri = $this->stringParam('redirect_uri');
        if ($code === '' || $codeVerifier === '' || $redirectUri === '') {
            throw new OAuthException(
                'invalid_request',
                'code, code_verifier, redirect_uri are required.'
            );
        }

        $hash = $this->tokenHasher->hash($code);
        try {
            $authCode = $this->authCodeRepository->getByHash($hash);
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_grant', 'Authorization code not recognized.');
        }

        if ($authCode->getOAuthClientId() !== (int) $client->getId()) {
            throw new OAuthException('invalid_grant', 'Code does not belong to this client.');
        }
        if (!$authCode->isValid()) {
            throw new OAuthException('invalid_grant', 'Authorization code is expired or already used.');
        }
        if (!hash_equals($authCode->getRedirectUri(), $redirectUri)) {
            throw new OAuthException('invalid_grant', 'redirect_uri mismatch.');
        }
        if (!$this->pkceVerifier->verify(
            $codeVerifier,
            $authCode->getCodeChallenge(),
            $authCode->getCodeChallengeMethod()
        )) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }

        // Compare-and-swap: only the caller that flips used_at from NULL → now gets to issue
        // tokens. A concurrent redemption sees `false` and is rejected per OAuth 2.1 §4.1.3.
        $authCodeId = $authCode->getId();
        if ($authCodeId === null) {
            throw new OAuthException('server_error', 'Auth code row is missing id.', 500);
        }
        if (!$this->authCodeRepository->markUsed($authCodeId)) {
            throw new OAuthException('invalid_grant', 'Authorization code is expired or already used.');
        }

        $clientId = $client->getId();
        if ($clientId === null) {
            throw new OAuthException('server_error', 'OAuth client row is missing id.', 500);
        }

        // The auth code's `granted_tools_json` was intersected against the consenting
        // admin's tick selection; trust it here. The OAuth-protocol scope echo on the
        // response is derived from the same tool list inside the issuer.
        $grantedTools = $authCode->getGrantedTools();
        $isWildcardGrant = $grantedTools !== null && ToolGrantResolver::isWildcard($grantedTools);
        // For wildcard grants the granted-tool list is gone — derive writes from the
        // protocol scope the consent screen stored alongside it (a snapshot of what
        // the admin could actually do at consent time).
        $allowWrites = $isWildcardGrant
            ? str_contains((string) $authCode->getScope(), 'mcp:write')
            : ($grantedTools !== null && $this->toolGrantResolver->hasWriteTool($grantedTools));
        // Wildcard grants collapse to NULL scopes on the token — runtime then treats
        // every tool the admin's role permits as in-scope (future tools auto-apply).
        $toolNamesForIssue = $isWildcardGrant ? null : $grantedTools;

        $pair = $this->accessTokenIssuer->issue(
            oauthClientId: $clientId,
            oauthClientName: $client->getName(),
            adminUserId: $authCode->getAdminUserId(),
            allowWrites: $allowWrites,
            toolNames: $toolNamesForIssue
        );

        return $this->emitTokenResponse($pair, $pair->grantedScope);
    }

    /**
     * @param Client $client
     * @return ResponseInterface
     * @throws OAuthException
     */
    private function handleRefresh(Client $client): ResponseInterface
    {
        $refreshTokenPlain = $this->stringParam('refresh_token');
        if ($refreshTokenPlain === '') {
            throw new OAuthException('invalid_request', 'refresh_token is required.');
        }

        $clientId = $client->getId();
        if ($clientId === null) {
            throw new OAuthException('server_error', 'Client row missing id.', 500);
        }

        $pair = $this->refreshTokenRotator->rotate($refreshTokenPlain, (int) $clientId);
        return $this->emitTokenResponse($pair, $pair->grantedScope);
    }

    /**
     * @param IssuedTokenPair $pair
     * @param ?string $scope
     * @return ResponseInterface
     */
    private function emitTokenResponse(
        IssuedTokenPair $pair,
        ?string $scope
    ): ResponseInterface {
        $payload = [
            'access_token' => $pair->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $pair->expiresIn,
            'refresh_token' => $pair->refreshToken,
        ];
        if ($scope !== null && $scope !== '') {
            $payload['scope'] = $scope;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{}';
        }
        $this->response->setHttpResponseCode(200);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setHeader('Cache-Control', 'no-store', true);
        $this->response->setHeader('Pragma', 'no-cache', true);
        $this->corsResponder->applyHeaders($this->response, self::ALLOWED_METHODS);
        $this->response->setBody($body);
        return $this->response;
    }

    /**
     * @param string $name
     * @return string
     */
    private function stringParam(string $name): string
    {
        $value = $this->request->getParam($name);
        return is_string($value) ? $value : '';
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
