<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\OAuth;

use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * `GET|POST /mcp/oauth/authorize` — OAuth 2.1 authorization endpoint.
 *
 * GET (Task 17, this file): validates query params, then either renders the
 * "log in to admin first" page or the consent screen. POST handling is stubbed
 * here and lands in Task 18.
 *
 * Per OAuth 2.1 §4.1.2.1 there are two error families:
 *   - Bad `client_id` / `redirect_uri` → render error inline (we MUST NOT
 *     redirect to a non-allowlisted URI).
 *   - Bad protocol parameters (`response_type`, PKCE) → 302 redirect back to
 *     the registered `redirect_uri` with `error=...&state=...`.
 */
class Authorize implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly PageFactory $pageFactory,
        private readonly ClientRepository $clientRepository,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AdminSession $adminSession
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        try {
            $params = $this->validateAndExtractParams();
        } catch (OAuthException $e) {
            // Errors that MUST NOT redirect (per OAuth 2.1 §4.1.2.1):
            // invalid_client, invalid_request when redirect_uri itself is bad/missing.
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

        if ($this->request->getMethod() === 'POST') {
            return $this->handleConsentSubmit($params);
        }

        if (!$this->adminSession->isLoggedIn()) {
            return $this->renderLoginRequired($params);
        }

        return $this->renderConsent($params);
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
     */
    private function renderLoginRequired(array $params): ResultInterface
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set('OAuth — Login Required');
        $block = $page->getLayout()->getBlock('mcp.oauth.authorize.login_required');
        if ($block instanceof AbstractBlock) {
            $block->setData('oauth_params', $params);
            $block->setData('current_url', $this->request->getUriString());
        }
        return $page;
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
     */
    private function renderConsent(array $params): ResultInterface
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set('OAuth — Authorize ' . $params['client']->getName());
        $block = $page->getLayout()->getBlock('mcp.oauth.authorize.consent');
        if ($block instanceof AbstractBlock) {
            $block->setData('oauth_params', $params);
            $admin = $this->adminSession->getUser();
            $block->setData('admin_user_id', $admin === null ? 0 : (int) $admin->getId());
            $block->setData('current_url', $this->request->getUriString());
        }
        return $page;
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
     */
    private function handleConsentSubmit(array $params): ResponseInterface|ResultInterface
    {
        unset($params);
        throw new \LogicException('Authorize POST handling lands in Task 18.');
    }

    /**
     * Opt out of form-key CSRF. The Task 18 POST handler validates the form
     * key explicitly inside `handleConsentSubmit` so a missing-key request
     * surfaces as an OAuth `access_denied` rather than a 403 form-key page.
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
