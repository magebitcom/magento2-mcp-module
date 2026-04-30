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
use Magebit\Mcp\Model\OAuth\InlineErrorRenderer;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use Magebit\Mcp\Model\OAuth\ScopeValidator;
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
 * Public-facing `GET /mcp/oauth/authorize` advertised in the RFC 8414 metadata.
 *
 * Validates OAuth params, stashes them in a single-use handoff record keyed by a
 * random nonce, and 302-redirects the browser to the adminhtml consent URL —
 * keeping the admin URL out of the public discovery document.
 *
 * Two error families per OAuth 2.1 §4.1.2.1:
 *   - redirect_uri / client_id failures render inline (no safe place to bounce);
 *   - protocol failures redirect back to the registered URI with `error=…`.
 */
class Authorize implements HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param ClientRepository $clientRepository
     * @param RedirectUriValidator $redirectUriValidator
     * @param AuthorizeHandoffStorage $handoffStorage
     * @param TokenGenerator $tokenGenerator
     * @param BackendUrl $backendUrl
     * @param ScopeValidator $scopeValidator
     * @param InlineErrorRenderer $inlineErrorRenderer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly ClientRepository $clientRepository,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly TokenGenerator $tokenGenerator,
        private readonly BackendUrl $backendUrl,
        private readonly ScopeValidator $scopeValidator,
        private readonly InlineErrorRenderer $inlineErrorRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResponseInterface
     */
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

        // Canonicalise so the admin side sees a deduped, validated string regardless
        // of what the client put on the wire.
        $canonicalScope = $this->scopeValidator->canonicalize(
            $this->scopeValidator->parse($params['scope'])
        );

        $nonce = $this->tokenGenerator->generate();
        $this->handoffStorage->store($nonce, [
            'client_id' => $params['client_id'],
            'redirect_uri' => $params['redirect_uri'],
            'state' => $params['state'],
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            'scope' => $canonicalScope,
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
        $this->response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate', true);
        $this->response->setBody('');
        return $this->response;
    }

    /**
     * @return array{
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
     * @param array{
     *     client: Client,
     *     client_id: string,
     *     redirect_uri: string,
     *     state: ?string,
     *     code_challenge: ?string,
     *     code_challenge_method: ?string,
     *     scope: ?string,
     *     response_type: ?string
     * } $params
     * @return void
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

        // Reject unknown scope values now (with `invalid_scope` redirect) before we
        // touch the handoff table. The protocol scope is only a pre-tick hint for the
        // consent screen — the per-tool cap on the client row is what bounds the grant.
        $this->scopeValidator->parse($params['scope']);
    }

    /**
     * @param string $name
     * @return ?string
     */
    private function stringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);
        if (!is_string($value)) {
            return null;
        }
        return $value;
    }

    /**
     * @param OAuthException $e
     * @return ResponseInterface
     */
    private function renderInlineError(OAuthException $e): ResponseInterface
    {
        return $this->inlineErrorRenderer->render(
            $this->response,
            $e->httpStatus,
            $e->oauthError,
            $e->getMessage()
        );
    }

    /**
     * @param string $redirectUri
     * @param ?string $state
     * @param string $errorCode
     * @param string $description
     * @return ResponseInterface
     */
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
     * Opt out of form-key CSRF; the admin-area controller revalidates before issuing.
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
