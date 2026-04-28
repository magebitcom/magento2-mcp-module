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
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\AuthCodeRepository;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\IssuedTokenPair;
use Magebit\Mcp\Model\OAuth\OAuthErrorResponse;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * `POST /mcp/oauth/token` — OAuth 2.1 token endpoint.
 *
 * Implements the `authorization_code` grant in Task 19. The `refresh_token`
 * grant is intentionally stubbed with `unsupported_grant_type` and lands in
 * Task 20 on the same controller.
 *
 * Supports both `client_secret_basic` (HTTP Basic Authorization header) and
 * `client_secret_post` (form-encoded `client_id` / `client_secret` fields)
 * client authentication, as advertised by the authorization-server metadata.
 *
 * Per RFC 6749 §5.2 errors are emitted as JSON via {@see OAuthErrorResponse},
 * with `WWW-Authenticate: Basic` added on `invalid_client`.
 */
class Token implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly ClientRepository $clientRepository,
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly PkceVerifier $pkceVerifier,
        private readonly AccessTokenIssuer $accessTokenIssuer,
        private readonly OAuthErrorResponse $errorResponse,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResponseInterface
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->response->setHttpResponseCode(405);
            $this->response->setHeader('Allow', 'POST', true);
            $this->response->setBody('');
            return $this->response;
        }

        try {
            $client = $this->authenticateClient();
            $grantType = $this->stringParam('grant_type');

            return match ($grantType) {
                'authorization_code' => $this->handleAuthCode($client),
                'refresh_token' => throw new OAuthException(
                    'unsupported_grant_type',
                    'refresh_token grant lands in Task 20.'
                ),
                default => throw new OAuthException(
                    'unsupported_grant_type',
                    'Only authorization_code is supported.'
                ),
            };
        } catch (OAuthException $e) {
            return $this->errorResponse->emit($this->response, $e);
        } catch (\Throwable $e) {
            $this->logger->error('OAuth token endpoint failed.', ['exception' => $e]);
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

        // Mark used BEFORE issuing tokens so a concurrent reuse loses the race.
        $authCodeId = $authCode->getId();
        if ($authCodeId === null) {
            throw new OAuthException('server_error', 'Auth code row is missing id.', 500);
        }
        $this->authCodeRepository->markUsed($authCodeId);

        $clientId = $client->getId();
        if ($clientId === null) {
            throw new OAuthException('server_error', 'OAuth client row is missing id.', 500);
        }

        $pair = $this->accessTokenIssuer->issue(
            oauthClientId: $clientId,
            oauthClientName: $client->getName(),
            adminUserId: $authCode->getAdminUserId(),
            // V1: rely on per-token allow_writes set elsewhere; default false here.
            allowWrites: false,
            scope: $authCode->getScope()
        );

        return $this->emitTokenResponse($pair, $authCode->getScope());
    }

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
        $this->response->setBody($body);
        return $this->response;
    }

    private function stringParam(string $name): string
    {
        $value = $this->request->getParam($name);
        return is_string($value) ? $value : '';
    }

    /**
     * Opt out of form-key CSRF. The token endpoint is authenticated via the
     * client's HTTP Basic / form-post credentials, which is the spec-mandated
     * CSRF gate (a form-key cookie wouldn't even reach a confidential client).
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
