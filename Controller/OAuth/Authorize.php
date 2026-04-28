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
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Public-facing `GET /mcp/oauth/authorize` — the URL advertised in the
 * RFC 8414 authorization-server-metadata document.
 *
 * This controller deliberately does **not** render the consent screen and
 * does **not** read the admin session: those live behind the admin URL,
 * which we never expose in any public document. Instead it:
 *
 *   1. Validates the OAuth params (per OAuth 2.1 §4.1.2.1, two error
 *      families: redirect_uri/client_id failures render inline; protocol
 *      failures redirect back to the registered redirect_uri with `error=`).
 *   2. Stashes the validated params in a short-lived server-side handoff
 *      keyed by a single-use random nonce.
 *   3. 302-redirects the user's browser to the admin-area authorize URL
 *      with the nonce — that URL lives under `/<adminFrontName>/...`,
 *      which Magento's admin auth wraps automatically.
 *
 * The OAuth client (Claude, ChatGPT, etc.) never follows the user's browser
 * redirect, so the admin URL never reaches a third party. The user briefly
 * sees the admin URL in their address bar — they're an admin, so that's
 * expected.
 */
class Authorize implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly ClientRepository $clientRepository,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly TokenGenerator $tokenGenerator,
        private readonly BackendUrl $backendUrl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResponseInterface
    {
        try {
            $params = $this->validateAndExtractParams();
        } catch (OAuthException $e) {
            return $this->renderInlineError($e);
        }

        try {
            $this->validateProtocolParams($params);
        } catch (OAuthException $e) {
            return $this->redirectWithError(
                $params['redirect_uri'],
                $params['state'],
                $e->oauthError,
                $e->getMessage()
            );
        }

        $nonce = $this->tokenGenerator->generate();
        $this->handoffStorage->store($nonce, [
            'client_id' => $params['client_id'],
            'redirect_uri' => $params['redirect_uri'],
            'state' => $params['state'],
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'scope' => $params['scope'],
            'response_type' => $params['response_type'],
        ]);

        $this->logger->info('OAuth authorize handoff stored.', [
            'client_id' => $params['client_id'],
        ]);

        $adminUrl = $this->backendUrl->getUrl(
            'magebit_mcp/oauth/authorize',
            ['h' => $nonce, '_secure' => true]
        );

        $this->response->setHttpResponseCode(302);
        $this->response->setHeader('Location', $adminUrl, true);
        // The handoff nonce is single-use, but be belt-and-suspenders here —
        // we still don't want intermediate caches retaining the redirect.
        $this->response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate', true);
        $this->response->setBody('');
        return $this->response;
    }

    /**
     * @phpstan-return array{
     *     client: Client,
     *     client_id: string,
     *     redirect_uri: string,
     *     state: ?string,
     *     code_challenge: ?string,
     *     code_challenge_method: ?string,
     *     scope: ?string,
     *     response_type: ?string
     * }
     * @throws OAuthException
     */
    private function validateAndExtractParams(): array
    {
        $clientId = $this->stringParam('client_id');
        if ($clientId === null || $clientId === '') {
            throw new OAuthException('invalid_client', 'client_id is required.', 400);
        }

        try {
            $client = $this->clientRepository->getByClientId($clientId);
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_client', 'Unknown client.', 400);
        }

        $redirectUri = $this->stringParam('redirect_uri');
        if ($redirectUri === null
            || $redirectUri === ''
            || !$this->redirectUriValidator->isAllowed($client, $redirectUri)
        ) {
            throw new OAuthException('invalid_request', 'Invalid redirect URI.', 400);
        }

        return [
            'client' => $client,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $this->stringParam('state'),
            'code_challenge' => $this->stringParam('code_challenge'),
            'code_challenge_method' => $this->stringParam('code_challenge_method'),
            'scope' => $this->stringParam('scope'),
            'response_type' => $this->stringParam('response_type'),
        ];
    }

    /**
     * @phpstan-param array{
     *     client: Client,
     *     client_id: string,
     *     redirect_uri: string,
     *     state: ?string,
     *     code_challenge: ?string,
     *     code_challenge_method: ?string,
     *     scope: ?string,
     *     response_type: ?string
     * } $params
     *
     * @throws OAuthException
     */
    private function validateProtocolParams(array $params): void
    {
        if ($params['response_type'] !== 'code') {
            throw new OAuthException(
                'unsupported_response_type',
                'Only response_type=code is supported.'
            );
        }

        if ($params['code_challenge'] === null || $params['code_challenge'] === '') {
            throw new OAuthException(
                'invalid_request',
                'code_challenge is required (PKCE).'
            );
        }

        if ($params['code_challenge_method'] !== PkceVerifier::METHOD_S256) {
            throw new OAuthException(
                'invalid_request',
                'Only S256 code_challenge_method is supported.'
            );
        }
    }

    private function stringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);
        if (!is_string($value)) {
            return null;
        }
        return $value;
    }

    private function renderInlineError(OAuthException $e): ResponseInterface
    {
        $this->response->setHttpResponseCode($e->httpStatus);
        $this->response->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->response->setBody(sprintf(
            '<!doctype html><html><body><h1>OAuth error</h1>'
            . '<p><strong>%s</strong>: %s</p></body></html>',
            htmlspecialchars($e->oauthError, ENT_QUOTES),
            htmlspecialchars($e->getMessage(), ENT_QUOTES)
        ));
        return $this->response;
    }

    private function redirectWithError(
        string $redirectUri,
        ?string $state,
        string $errorCode,
        string $description
    ): ResponseInterface {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $params = ['error' => $errorCode, 'error_description' => $description];
        if ($state !== null) {
            $params['state'] = $state;
        }
        $query = http_build_query($params);
        $this->response->setHttpResponseCode(302);
        $this->response->setHeader('Location', $redirectUri . $separator . $query, true);
        $this->response->setBody('');
        return $this->response;
    }

    /**
     * Opt out of form-key CSRF — this endpoint accepts only GET, validates
     * its query params explicitly, and writes a single-use handoff before
     * redirecting. The admin-area controller revalidates everything before
     * issuing the auth code.
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
