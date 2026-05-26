<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use InvalidArgumentException;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\AuthorizationOptions;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Throwable;

/**
 * POST `magebit_mcp/oauthclient/save` — create + edit. On create the freshly minted
 * plaintext secret is rendered inline so it is never written to session storage.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    /**
     * @param Context $context
     * @param ClientCredentialIssuer $issuer
     * @param ClientRepository $clientRepository
     * @param ToolRegistryInterface $toolRegistry
     * @param FormDataPersistence $formDataPersistence
     * @param UserCollectionFactory $userCollectionFactory
     * @param TokenRepository $tokenRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ClientCredentialIssuer $issuer,
        private readonly ClientRepository $clientRepository,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly FormDataPersistence $formDataPersistence,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly TokenRepository $tokenRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $rawAny = $request->getPostValue();
        if (!is_array($rawAny) || $rawAny === []) {
            $this->messageManager->addErrorMessage((string) __('Missing form payload.'));
            return $redirect->setPath('*/*/index');
        }
        // Numeric-keyed entries from getPostValue() can't map to form fields and are dropped.
        $raw = [];
        foreach ($rawAny as $key => $value) {
            if (is_string($key)) {
                $raw[$key] = $value;
            }
        }

        $name = $this->extractName($raw);
        $redirectUris = $this->extractRedirectUris($raw);
        $allowedTools = $this->extractAllowedTools($raw);
        $auth = $this->extractAuthorizationOptions($raw);

        $idRaw = $raw['id'] ?? 0;
        $editingId = is_scalar($idRaw) ? (int) $idRaw : 0;

        $authError = $this->validateAuthorizationOptions($auth);

        if ($name === '' || $redirectUris === [] || $allowedTools === [] || $authError !== null) {
            $this->preserveFormData($raw, $allowedTools);
            if ($name === '') {
                $this->messageManager->addErrorMessage((string) __('Name is required.'));
            }
            if ($redirectUris === []) {
                $this->messageManager->addErrorMessage((string) __('At least one redirect URI is required.'));
            }
            if ($allowedTools === []) {
                $this->messageManager->addErrorMessage((string) __('Pick at least one tool this client may request.'));
            }
            if ($authError !== null) {
                $this->messageManager->addErrorMessage($authError);
            }
            return $editingId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $editingId])
                : $redirect->setPath('*/*/new');
        }

        if ($editingId > 0) {
            return $this->handleUpdate($redirect, $editingId, $name, $redirectUris, $allowedTools, $auth, $raw);
        }

        return $this->handleCreate($redirect, $name, $redirectUris, $allowedTools, $auth, $raw);
    }

    /**
     * @param Redirect $redirect
     * @param int $editingId
     * @param string $name
     * @param array<int, string> $redirectUris
     * @param array<int, string> $allowedTools
     * @param AuthorizationOptions $auth
     * @param array<string, mixed> $raw
     * @return Redirect
     */
    private function handleUpdate(
        Redirect $redirect,
        int $editingId,
        string $name,
        array $redirectUris,
        array $allowedTools,
        AuthorizationOptions $auth,
        array $raw
    ): Redirect {
        try {
            $client = $this->clientRepository->getById($editingId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('OAuth client #%1 no longer exists.', $editingId)
            );
            return $redirect->setPath('*/*/index');
        }

        $wasDisabled = $client->isDisabled();

        try {
            $client->setName($name);
            $client->setRedirectUris($redirectUris);
            $client->setAllowedTools($allowedTools);
            $this->issuer->applyAuthorizationOptions($client, $auth);
            $this->clientRepository->save($client);
        } catch (Throwable $e) {
            $this->preserveFormData($raw, $allowedTools);
            $this->messageManager->addErrorMessage(
                (string) __('Failed to update OAuth client: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/edit', ['id' => $editingId]);
        }

        // Symmetric with RotateSecret: flipping a client to disabled must also
        // revoke its live bearer tokens. Without this, the admin-UI "Disabled"
        // toggle only blocks new auth-code/refresh flows — every access token
        // issued before the click keeps authenticating at /mcp until natural
        // expiry. Failure here is surfaced as a warning so the save itself
        // still reports success and the admin can retry revocation.
        if ($auth->disabled && !$wasDisabled) {
            try {
                $revoked = $this->tokenRepository->revokeAllForClient($editingId);
                $this->logger->info('OAuth client disabled — live tokens revoked.', [
                    'client_id' => $client->getClientId(),
                    'tokens_revoked' => $revoked,
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('OAuth client disable: token revocation failed.', [
                    'client_id' => $client->getClientId(),
                    'exception' => $e,
                ]);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Client disabled, but revoking live tokens failed: %1. Retry via the listing'
                        . ' or revoke individual tokens manually.',
                        $e->getMessage()
                    )
                );
            }
        }

        $this->messageManager->addSuccessMessage(
            (string) __('OAuth client "%1" updated.', $name)
        );
        return $redirect->setPath('*/*/index');
    }

    /**
     * @param Redirect $redirect
     * @param string $name
     * @param array<int, string> $redirectUris
     * @param array<int, string> $allowedTools
     * @param AuthorizationOptions $auth
     * @param array<string, mixed> $raw
     * @return ResultInterface
     */
    private function handleCreate(
        Redirect $redirect,
        string $name,
        array $redirectUris,
        array $allowedTools,
        AuthorizationOptions $auth,
        array $raw
    ): ResultInterface {
        try {
            $issued = $this->issuer->issue($name, $redirectUris, $allowedTools, $auth);
        } catch (InvalidArgumentException $e) {
            $this->preserveFormData($raw, $allowedTools);
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/new');
        } catch (Throwable $e) {
            $this->preserveFormData($raw, $allowedTools);
            $this->messageManager->addErrorMessage(
                (string) __('Failed to create OAuth client: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/new');
        }

        return $this->renderCredentialsPage($name, $issued['client_id'], $issued['client_secret']);
    }

    /**
     * @param string $name
     * @param string $clientId
     * @param string $clientSecret
     * @return Page
     */
    private function renderCredentialsPage(string $name, string $clientId, string $clientSecret): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Magebit_Mcp::mcp_oauth_clients');
        $page->getConfig()->getTitle()->prepend((string) __('OAuth Client Created'));
        // No-store keeps the credentials out of proxies and back/forward cache; the secret
        // should never live anywhere except the operator's clipboard.
        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $page->setHeader('Pragma', 'no-cache', true);

        $block = $page->getLayout()->getBlock('mcp.oauthclient.created');
        if ($block instanceof AbstractBlock) {
            $block->setData('name', $name);
            $block->setData('client_id', $clientId);
            $block->setData('client_secret', $clientSecret);
        }
        return $page;
    }

    /**
     * @param array<string, mixed> $raw
     * @return string
     */
    private function extractName(array $raw): string
    {
        $value = $raw['name'] ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        if (strlen($value) > 128) {
            $value = substr($value, 0, 128);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, string>
     */
    private function extractRedirectUris(array $raw): array
    {
        $value = $raw['redirect_uris'] ?? '';
        if (!is_scalar($value)) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, string>
     */
    private function extractAllowedTools(array $raw): array
    {
        $resourceIds = $raw['resource'] ?? [];
        if (!is_array($resourceIds) || $resourceIds === []) {
            return [];
        }

        $toolByAclId = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $toolByAclId[$tool->getAclResource()] = $tool->getName();
        }

        $names = [];
        $seen = [];
        foreach ($resourceIds as $resourceId) {
            if (!is_string($resourceId)) {
                continue;
            }
            $rid = trim($resourceId);
            if ($rid === '') {
                continue;
            }
            if ($rid === ToolResourceTree::ROOT_RESOURCE_ID || str_starts_with($rid, 'mcp_group_')) {
                continue;
            }
            $name = $toolByAclId[$rid] ?? null;
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $names[] = $name;
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string> $allowedTools
     */
    private function preserveFormData(array $raw, array $allowedTools): void
    {
        $this->formDataPersistence->set([
            'name' => $raw['name'] ?? '',
            'redirect_uris' => $raw['redirect_uris'] ?? '',
            'allowed_tools' => $allowedTools,
            'auth_mode' => $raw['auth_mode'] ?? AuthMode::PERSONAL->value,
            'service_admin_user_id' => $raw['service_admin_user_id'] ?? '',
            'allowed_admin_user_ids' => is_array($raw['allowed_admin_user_ids'] ?? null)
                ? $raw['allowed_admin_user_ids']
                : [],
            'allowed_admin_role_ids' => is_array($raw['allowed_admin_role_ids'] ?? null)
                ? $raw['allowed_admin_role_ids']
                : [],
            'disabled' => $raw['disabled'] ?? '0',
        ]);
    }

    /**
     * @param array<string, mixed> $raw
     * @return AuthorizationOptions
     */
    private function extractAuthorizationOptions(array $raw): AuthorizationOptions
    {
        $modeRaw = $raw['auth_mode'] ?? AuthMode::PERSONAL->value;
        $mode = AuthMode::tryFrom(is_string($modeRaw) ? $modeRaw : '') ?? AuthMode::PERSONAL;

        $serviceAdminRaw = $raw['service_admin_user_id'] ?? null;
        $serviceAdmin = is_scalar($serviceAdminRaw) && (int) $serviceAdminRaw > 0
            ? (int) $serviceAdminRaw
            : null;

        $disabledRaw = $raw['disabled'] ?? '0';
        $disabled = is_scalar($disabledRaw) && (int) $disabledRaw === 1;

        return new AuthorizationOptions(
            mode: $mode,
            serviceAdminUserId: $serviceAdmin,
            allowedAdminUserIds: $this->extractIntList($raw['allowed_admin_user_ids'] ?? null),
            allowedAdminRoleIds: $this->extractIntList($raw['allowed_admin_role_ids'] ?? null),
            disabled: $disabled
        );
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function extractIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $int = (int) $item;
            if ($int <= 0 || isset($seen[$int])) {
                continue;
            }
            $seen[$int] = true;
            $out[] = $int;
        }
        return $out;
    }

    /**
     * @param AuthorizationOptions $auth
     * @return string|null Error string on misconfiguration, null on success.
     */
    private function validateAuthorizationOptions(AuthorizationOptions $auth): ?string
    {
        if ($auth->mode === AuthMode::SHARED) {
            if ($auth->serviceAdminUserId === null) {
                return (string) __(
                    'Shared mode requires a Service Admin User. Pick the admin every issued token should be'
                    . ' bound to, or switch to Personal mode.'
                );
            }
            if (!$this->isActiveAdminUser($auth->serviceAdminUserId)) {
                return (string) __(
                    'The selected Service Admin User must be an active admin. Pick a different admin.'
                );
            }
        }

        foreach ($auth->allowedAdminUserIds as $userId) {
            if (!$this->isActiveAdminUser($userId)) {
                return (string) __(
                    'Allowed Admin Users contains an inactive or unknown admin. Refresh the page and reselect.'
                );
            }
        }

        return null;
    }

    /**
     * @param int $userId
     * @return bool
     */
    private function isActiveAdminUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('user_id', ['eq' => $userId]);
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->setPageSize(1);
        return $collection->getSize() === 1;
    }
}
