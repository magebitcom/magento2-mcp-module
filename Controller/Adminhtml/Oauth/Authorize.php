<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Oauth;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\OAuth\AdminAuthorizationDecision;
use Magebit\Mcp\Model\OAuth\AdminAuthorizationGate;
use Magebit\Mcp\Model\OAuth\AuthCodeIssuer;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magebit\Mcp\Model\OAuth\ConsentParamsResolver;
use Magebit\Mcp\Model\OAuth\InlineErrorRenderer;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use Magebit\Mcp\Model\OAuth\Scope;
use Magebit\Mcp\Model\OAuth\ScopeValidator;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Adminhtml consent screen — reached only via the public authorize controller.
 * The granted tool set is the intersection of the client's allowed-tools, the
 * admin's role, and the form's tick selection.
 */
class Authorize extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_authorize';

    /**
     * Bypasses the secret-key URL check; the handoff nonce + admin session authenticate.
     *
     * @var string[]
     */
    protected $_publicActions = ['authorize'];

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param AuthorizeHandoffStorage $handoffStorage
     * @param RedirectUriValidator $redirectUriValidator
     * @param AuthCodeIssuer $authCodeIssuer
     * @param ScopeValidator $scopeValidator
     * @param ToolGrantResolver $toolGrantResolver
     * @param ConsentParamsResolver $consentParamsResolver
     * @param InlineErrorRenderer $inlineErrorRenderer
     * @param AdminAuthorizationGate $adminAuthorizationGate
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly AuthorizeHandoffStorage $handoffStorage,
        private readonly RedirectUriValidator $redirectUriValidator,
        private readonly AuthCodeIssuer $authCodeIssuer,
        private readonly ScopeValidator $scopeValidator,
        private readonly ToolGrantResolver $toolGrantResolver,
        private readonly ConsentParamsResolver $consentParamsResolver,
        private readonly InlineErrorRenderer $inlineErrorRenderer,
        private readonly AdminAuthorizationGate $adminAuthorizationGate,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface|HttpResponse
     */
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
        return $this->renderConsent($nonce);
    }

    /**
     * @param string $nonce
     * @return ResultInterface|HttpResponse
     */
    private function renderConsent(string $nonce): ResultInterface|HttpResponse
    {
        $params = $this->handoffStorage->peek($nonce);
        if ($params === null) {
            return $this->renderInlineError(
                400,
                'invalid_request',
                'Authorization handoff has expired. Restart the connection from your MCP client.'
            );
        }
        $client = $this->consentParamsResolver->resolveClient($params);
        /** @var \Magento\User\Model\User|null $admin */
        $admin = $this->_auth->getUser();

        // Reject before the admin sees the screen — disabled clients, non-whitelisted
        // admins (personal), wrong-admin/un-pinned (shared) all redirect back with the OAuth error.
        if ($client !== null) {
            $decision = $this->adminAuthorizationGate->decide($client, $admin);
            if (!$decision->isAllowed()) {
                return $this->handleGateDenial(
                    $decision,
                    ConsentParamsResolver::stringFromParams($params, 'redirect_uri'),
                    ConsentParamsResolver::stringFromParams($params, 'state')
                );
            }
        }

        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate', true);
        $page->setHeader('Pragma', 'no-cache', true);
        $clientLabel = $client === null ? 'an MCP client' : $client->getName();
        $page->getConfig()->getTitle()->set('Authorize ' . $clientLabel);

        $requestedScopes = $this->scopeValidator->parse(
            ConsentParamsResolver::nullableStringFromParams($params, 'scope')
        );
        $preTicked = $this->consentParamsResolver->computePreTickedTools($client, $admin, $requestedScopes);

        $block = $page->getLayout()->getBlock('mcp.oauth.authorize.consent');
        if ($block instanceof AbstractBlock) {
            $block->setData('oauth_client', $client);
            $block->setData('current_url', $this->_url->getCurrentUrl());
            $block->setData('requested_scopes', $requestedScopes);
            $block->setData('pre_ticked_tools', $preTicked);
            $block->setData('is_shared_mode', $client !== null && $client->getAuthMode() === AuthMode::SHARED);
        }
        return $page;
    }

    /**
     * @param string $nonce
     * @return HttpResponse
     */
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

        $client = $this->consentParamsResolver->resolveClient($params);
        $redirectUri = ConsentParamsResolver::stringFromParams($params, 'redirect_uri');
        if ($client === null || $redirectUri === '') {
            return $this->renderInlineError(400, 'invalid_request', 'Authorization request is no longer valid.');
        }

        // Re-validate the redirect URI in case an operator tightened the client's
        // allowlist while a consent screen was open.
        if (!$this->redirectUriValidator->isAllowed($client, $redirectUri)) {
            return $this->renderInlineError(400, 'invalid_request', 'Invalid redirect URI.');
        }

        $state = ConsentParamsResolver::stringFromParams($params, 'state');

        $action = $this->getRequest()->getParam('oauth_action');
        if (is_string($action) && $action === 'deny') {
            return $this->redirectToClient($redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'User denied authorization.',
                'state' => $state,
            ]);
        }

        /** @var \Magento\User\Model\User|null $admin */
        $admin = $this->_auth->getUser();
        $rawId = $admin?->getId();
        $adminUserId = is_scalar($rawId) ? (int) $rawId : 0;
        if ($admin === null || $adminUserId === 0) {
            $this->logger->warning('OAuth consent: admin session lost mid-flow.', ['redirect_uri' => $redirectUri]);
            return $this->redirectToClient($redirectUri, [
                'error' => 'server_error',
                'error_description' => 'Admin session lost during approval.',
                'state' => $state,
            ]);
        }

        // Re-check the gate even though renderConsent already did: account swap,
        // policy flip, or client disabled while the screen was open.
        $gateDecision = $this->adminAuthorizationGate->decide($client, $admin);
        if (!$gateDecision->isAllowed()) {
            return $this->handleGateDenial($gateDecision, $redirectUri, $state);
        }

        // Shared mode: pin the auth code to the service admin. Belt-and-braces — the
        // gate already enforced the same equality.
        if ($client->getAuthMode() === AuthMode::SHARED) {
            $serviceAdminId = $client->getServiceAdminUserId();
            if ($serviceAdminId !== null && $serviceAdminId > 0) {
                $adminUserId = $serviceAdminId;
            }
        }

        $rawResources = $this->getRequest()->getParam('resource');
        $tickedTools = $this->consentParamsResolver->parseSubmittedToolNames(
            is_array($rawResources) ? $rawResources : null
        );
        $grantedTools = $this->toolGrantResolver->intersect($client->getAllowedTools(), $tickedTools, $admin);
        if ($grantedTools === []) {
            // Admin un-ticked everything → equivalent to deny.
            return $this->redirectToClient($redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'No tools were approved.',
                'state' => $state,
            ]);
        }

        // When the client opts into "all current + future tools" and the admin accepts the
        // maximal grant their role permits, forward the wildcard sentinel onto the auth
        // code so the issued token is recorded as wildcard (NULL scopes_json) rather than
        // a snapshot — new tools shipped by satellite modules then auto-apply. The OAuth
        // protocol scope on the auth code stays the snapshot summary so the issued
        // token's allow_writes flag reflects what the admin could actually do at consent
        // time (a role elevation later still requires a fresh authorize flow).
        $grantedToolsForIssue = $grantedTools;
        $grantedScopeString = $this->toolGrantResolver->summarizeScope($grantedTools);
        if (ToolGrantResolver::isWildcard($client->getAllowedTools())
            && $this->toolGrantResolver->grantsAllAdminAccessibleTools($grantedTools, $admin)
        ) {
            $grantedToolsForIssue = [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL];
        }
        if ($grantedScopeString === '') {
            $grantedScopeString = Scope::READ->value;
        }

        $clientPk = $client->getId();
        if ($clientPk === null) {
            return $this->redirectToClient($redirectUri, [
                'error' => 'server_error',
                'error_description' => 'OAuth client row missing id.',
                'state' => $state,
            ]);
        }

        try {
            $code = $this->authCodeIssuer->issue(
                oauthClientId: $clientPk,
                adminUserId: $adminUserId,
                redirectUri: $redirectUri,
                codeChallenge: ConsentParamsResolver::stringFromParams($params, 'code_challenge'),
                codeChallengeMethod: ConsentParamsResolver::stringFromParams(
                    $params,
                    'code_challenge_method',
                    PkceVerifier::METHOD_S256
                ),
                scope: $grantedScopeString,
                grantedTools: $grantedToolsForIssue
            );
        } catch (OAuthException $e) {
            return $this->redirectToClient($redirectUri, [
                'error' => $e->oauthError,
                'error_description' => $e->getMessage(),
                'state' => $state,
            ]);
        }

        $this->logger->info('OAuth authorization code issued.', [
            'client_id' => $client->getClientId(),
            'admin_user_id' => $adminUserId,
            'tool_count' => count($grantedTools),
        ]);

        return $this->redirectToClient($redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * @param string $redirectUri
     * @param array<string, string|null> $params
     * @return HttpResponse
     */
    private function redirectToClient(string $redirectUri, array $params): HttpResponse
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $clean = array_filter($params, static fn (mixed $v): bool => $v !== null && $v !== '');
        $query = http_build_query($clean);
        $response = $this->httpResponse();
        $response->setHttpResponseCode(302);
        $response->setHeader('Location', $redirectUri . $separator . $query, true);
        $response->setBody('');
        return $response;
    }

    /**
     * @param int $httpStatus
     * @param string $error
     * @param string $description
     * @return HttpResponse
     */
    private function renderInlineError(int $httpStatus, string $error, string $description): HttpResponse
    {
        return $this->inlineErrorRenderer->render($this->httpResponse(), $httpStatus, $error, $description);
    }

    /**
     * @param AdminAuthorizationDecision $decision
     * @param string $redirectUri
     * @param string $state
     * @return HttpResponse Redirects to the client with the OAuth error, or inline if no redirect_uri.
     */
    private function handleGateDenial(
        AdminAuthorizationDecision $decision,
        string $redirectUri,
        string $state
    ): HttpResponse {
        $this->logger->info('OAuth consent denied by AdminAuthorizationGate.', [
            'decision' => $decision->value,
            'redirect_uri' => $redirectUri,
        ]);

        if ($redirectUri === '') {
            return $this->renderInlineError(403, $decision->oauthError(), $decision->description());
        }

        return $this->redirectToClient($redirectUri, [
            'error' => $decision->oauthError(),
            'error_description' => $decision->description(),
            'state' => $state,
        ]);
    }

    /**
     * @return HttpResponse
     */
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
