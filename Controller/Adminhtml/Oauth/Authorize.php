<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Oauth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\OAuth\AuthCodeIssuer;
use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Adminhtml `GET|POST /<adminFrontName>/magebit_mcp/oauth/authorize` —
 * the actual consent screen. Reached only via the public frontend
 * authorize controller, which writes a single-use handoff and redirects
 * the user's browser here.
 *
 * Why this controller lives in adminhtml:
 *  - Magento's admin auth wraps it for free. Not logged in? It redirects
 *    to the admin login screen with `?referer=...` and bounces back here
 *    after login. No `login_required.phtml` to maintain.
 *  - The admin session cookie is path-scoped to the admin frontName, so
 *    a frontend controller can never read it. The frontend authorize URL
 *    is the public face advertised in the AS metadata; the admin URL is
 *    where the cookie can actually do its job.
 *
 * The URL template registered for this action is `magebit_mcp/oauth/authorize`
 * — i.e. the default Magento adminhtml URL building uses route =
 * `magebit_mcp` (registered in etc/adminhtml/routes.xml), controller path
 * = `oauth`, action = `authorize` (this class).
 *
 * Secret-key bypass: the public flow generates the admin URL on a
 * frontend request — there is no admin session at that point, so the
 * URL cannot carry the session-bound `?key=…` value Magento normally
 * uses. {@see Action::$_publicActions} signals that this action accepts
 * GET requests without a secret key (form_key still applies on POST).
 */
class Authorize extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_authorize';

    /**
     * Action names that bypass the secret-key URL check. The handoff nonce
     * + admin session combine to authenticate the request.
     *
     * @var string[]
     */
    protected $_publicActions = ['authorize'];

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly ClientRepository $clientRepository,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AuthCodeIssuer $authCodeIssuer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|HttpResponse
    {
        $request = $this->getRequest();
        $nonce = $request->getParam('h');
        if (!is_string($nonce) || $nonce === '') {
            return $this->renderInlineError(400, 'invalid_request', 'Missing handoff parameter.');
        }

        if ($request instanceof HttpRequest && $request->isPost()) {
            return $this->handleSubmit($nonce);
        }

        $params = $this->handoffStorage->peek($nonce);
        if ($params === null) {
            return $this->renderInlineError(
                400,
                'invalid_request',
                'Authorization handoff has expired. Restart the connection from your MCP client.'
            );
        }

        return $this->renderConsent($params);
    }

    /**
     * @phpstan-param array<string, mixed> $params
     */
    private function renderConsent(array $params): ResultInterface
    {
        $client = $this->resolveClient($params);

        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate', true);
        $page->setHeader('Pragma', 'no-cache', true);
        $clientLabel = $client === null ? 'an MCP client' : $client->getName();
        $page->getConfig()->getTitle()->set('Authorize ' . $clientLabel);

        $block = $page->getLayout()->getBlock('mcp.oauth.authorize.consent');
        if ($block instanceof AbstractBlock) {
            $block->setData('oauth_client', $client);
            $block->setData('current_url', $this->_url->getCurrentUrl());
        }
        return $page;
    }

    private function handleSubmit(string $nonce): HttpResponse
    {
        $params = $this->handoffStorage->consume($nonce);
        if ($params === null) {
            return $this->renderInlineError(
                400,
                'invalid_request',
                'Authorization handoff has expired. Restart the connection from your MCP client.'
            );
        }

        $client = $this->resolveClient($params);
        $redirectUri = $this->stringFromParams($params, 'redirect_uri');
        if ($client === null || $redirectUri === '') {
            return $this->renderInlineError(400, 'invalid_request', 'Authorization request is no longer valid.');
        }

        // Re-validate the redirect URI against the client's allow-list — the
        // handoff was written by the public controller after validation, but
        // we re-check here so a mid-flight client edit (e.g. an operator
        // tightening the allow-list while a consent screen is open) can't be
        // bypassed.
        if (!$this->redirectUriValidator->isAllowed($client, $redirectUri)) {
            return $this->renderInlineError(400, 'invalid_request', 'Invalid redirect URI.');
        }

        $state = $this->stringFromParams($params, 'state');

        $action = $this->getRequest()->getParam('oauth_action');
        $action = is_string($action) ? $action : '';
        if ($action === 'deny') {
            return $this->redirectToClient(
                $redirectUri,
                ['error' => 'access_denied', 'error_description' => 'User denied authorization.', 'state' => $state]
            );
        }

        $admin = $this->_auth->getUser();
        $rawId = $admin instanceof \Magento\User\Model\User ? $admin->getId() : null;
        $adminUserId = is_scalar($rawId) ? (int) $rawId : 0;
        if ($adminUserId === 0) {
            return $this->redirectToClient(
                $redirectUri,
                ['error' => 'server_error', 'error_description' => 'Admin session lost during approval.', 'state' => $state]
            );
        }

        try {
            $code = $this->authCodeIssuer->issue(
                oauthClientId: (int) $client->getId(),
                adminUserId: $adminUserId,
                redirectUri: $redirectUri,
                codeChallenge: $this->stringFromParams($params, 'code_challenge'),
                codeChallengeMethod: $this->stringFromParams($params, 'code_challenge_method', PkceVerifier::METHOD_S256),
                scope: $this->nullableStringFromParams($params, 'scope')
            );
        } catch (OAuthException $e) {
            return $this->redirectToClient(
                $redirectUri,
                ['error' => $e->oauthError, 'error_description' => $e->getMessage(), 'state' => $state]
            );
        }

        $this->logger->info('OAuth authorization code issued.', [
            'client_id' => $client->getClientId(),
            'admin_user_id' => $adminUserId,
        ]);

        return $this->redirectToClient($redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * @phpstan-param array<string, mixed> $params
     */
    private function resolveClient(array $params): ?Client
    {
        $clientId = $this->stringFromParams($params, 'client_id');
        if ($clientId === '') {
            return null;
        }
        try {
            return $this->clientRepository->getByClientId($clientId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @phpstan-param array<string, mixed> $params
     */
    private function stringFromParams(array $params, string $key, string $default = ''): string
    {
        $value = $params[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * @phpstan-param array<string, mixed> $params
     */
    private function nullableStringFromParams(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @phpstan-param array{error?: string, error_description?: string, code?: string, state?: string|null} $params
     */
    private function redirectToClient(string $redirectUri, array $params): HttpResponse
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $clean = array_filter(
            $params,
            static fn (mixed $v): bool => $v !== null && $v !== ''
        );
        $query = http_build_query($clean);
        $response = $this->httpResponse();
        $response->setHttpResponseCode(302);
        $response->setHeader('Location', $redirectUri . $separator . $query, true);
        $response->setBody('');
        return $response;
    }

    private function renderInlineError(int $httpStatus, string $error, string $description): HttpResponse
    {
        $response = $this->httpResponse();
        $response->setHttpResponseCode($httpStatus);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $response->setBody(sprintf(
            '<!doctype html><html><body><h1>OAuth error</h1>'
            . '<p><strong>%s</strong>: %s</p></body></html>',
            htmlspecialchars($error, ENT_QUOTES),
            htmlspecialchars($description, ENT_QUOTES)
        ));
        return $response;
    }

    private function httpResponse(): HttpResponse
    {
        $response = $this->getResponse();
        if (!$response instanceof HttpResponse) {
            throw new \LogicException('Adminhtml controller dispatched without an HTTP response.');
        }
        return $response;
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
