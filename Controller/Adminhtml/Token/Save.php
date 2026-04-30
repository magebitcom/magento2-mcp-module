<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Token;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\AbstractBlock;
use Throwable;

/**
 * POST `magebit_mcp/token/save` — mints a bearer; the freshly issued plaintext is
 * rendered inline on the response so it never lands in session storage.
 * Validation failures bounce back to New with the submitted form preserved via
 * the request-scoped {@see FormDataPersistence}.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /**
     * @param Context $context
     * @param AdminUserLookup $adminUserLookup
     * @param TokenFactory $tokenFactory
     * @param TokenGenerator $tokenGenerator
     * @param TokenHasher $tokenHasher
     * @param TokenRepository $tokenRepository
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param FormDataPersistence $formDataPersistence
     */
    public function __construct(
        Context $context,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly TokenFactory $tokenFactory,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly FormDataPersistence $formDataPersistence
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
        $raw = $request->getPostValue();
        if (!is_array($raw) || $raw === []) {
            $this->messageManager->addErrorMessage((string) __('Missing form payload.'));
            return $redirect->setPath('*/*/new');
        }

        try {
            $adminUserId = $this->extractAdminUserId($raw);
            $name = $this->extractName($raw);
            $allowWrites = (bool) ($raw['allow_writes'] ?? 0);
            $expiresAt = $this->extractExpiresAt($raw);

            $admin = $this->adminUserLookup->getById($adminUserId);
            if ((int) $admin->getIsActive() !== 1) {
                throw new \RuntimeException((string) __('Selected admin user is inactive.'));
            }
            $scopeToolNames = $this->extractScopeToolNames($raw, $admin);
        } catch (NoSuchEntityException $e) {
            $this->preserveFormData($raw);
            $this->messageManager->addErrorMessage((string) __('Admin user not found.'));
            return $redirect->setPath('*/*/new');
        } catch (Throwable $e) {
            $this->preserveFormData($raw);
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/new');
        }

        $plaintext = $this->tokenGenerator->generate();
        $hash = $this->tokenHasher->hash($plaintext);

        $token = $this->tokenFactory->create();
        $token->setAdminUserId($adminUserId);
        $token->setName($name);
        $token->setTokenHash($hash);
        $token->setAllowWrites($allowWrites);
        $token->setScopes($scopeToolNames === [] ? null : $scopeToolNames);
        if ($expiresAt !== null) {
            $token->setExpiresAt($expiresAt);
        }

        try {
            $this->tokenRepository->save($token);
        } catch (Throwable $e) {
            $this->preserveFormData($raw);
            $this->messageManager->addErrorMessage(
                (string) __('Failed to save connection: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/new');
        }

        return $this->renderCredentialsPage($name, $plaintext);
    }

    /**
     * Render the freshly-minted plaintext bearer inline so it never lands in session storage.
     *
     * @param string $name
     * @param string $plaintext
     * @return Page
     */
    private function renderCredentialsPage(string $name, string $plaintext): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Magebit_Mcp::mcp_tokens');
        $page->getConfig()->getTitle()->prepend((string) __('Connection Created'));
        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $page->setHeader('Pragma', 'no-cache', true);

        $block = $page->getLayout()->getBlock('mcp.token.created');

        if ($block instanceof AbstractBlock) {
            $block->setData('name', $name);
            $block->setData('plaintext', $plaintext);
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $raw
     * @phpstan-param array<string, mixed> $raw
     * @return int
     */
    private function extractAdminUserId(array $raw): int
    {
        $value = $raw['admin_user_id'] ?? null;
        if (!is_scalar($value) || (int) $value === 0) {
            throw new \InvalidArgumentException((string) __('Admin user is required.'));
        }
        return (int) $value;
    }

    /**
     * @param array $raw
     * @phpstan-param array<string, mixed> $raw
     * @return string
     */
    private function extractName(array $raw): string
    {
        $value = $raw['name'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException((string) __('Name is required.'));
        }
        $value = trim($value);
        if (strlen($value) > 128) {
            $value = substr($value, 0, 128);
        }
        return $value;
    }

    /**
     * @param array $raw
     * @phpstan-param array<string, mixed> $raw
     * @return string|null
     */
    private function extractExpiresAt(array $raw): ?string
    {
        $value = $raw['expires_at'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new \InvalidArgumentException(
                (string) __('Unable to parse expiration date: %1', $e->getMessage())
            );
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Translate the picker's ACL-id payload into the tool-name list that
     * `magebit_mcp_token.scopes_json` actually stores. `all_resources=1`
     * collapses to "any tool the admin's role grants at runtime" (NULL).
     * Submitted ACL ids must (a) belong to a registered tool and (b) be
     * granted by the chosen admin user's role — the latter is the
     * server-side counterpart to the disabled-node UI guard.
     *
     * @param array $raw
     * @phpstan-param array<string, mixed> $raw
     * @param \Magento\User\Model\User $admin
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function extractScopeToolNames(array $raw, \Magento\User\Model\User $admin): array
    {
        $allResourcesRaw = $raw['all_resources'] ?? '1';
        $allResources = is_scalar($allResourcesRaw) ? (string) $allResourcesRaw : '1';
        if ($allResources === '1') {
            return [];
        }

        $resourceIds = $raw['resource'] ?? [];
        if (!is_array($resourceIds) || $resourceIds === []) {
            return [];
        }

        $submitted = [];
        foreach ($resourceIds as $resourceId) {
            if (is_string($resourceId) && trim($resourceId) !== '') {
                $submitted[trim($resourceId)] = true;
            }
        }
        if ($submitted === []) {
            return [];
        }

        $toolByAclId = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $toolByAclId[$tool->getAclResource()] = $tool->getName();
        }

        $toolNames = [];
        foreach (array_keys($submitted) as $resourceId) {
            // Synthetic UI-only group nodes from the picker (`mcp_group_*`) and
            // the tools-root container itself never round-trip to a tool name.
            if ($resourceId === ToolResourceTree::ROOT_RESOURCE_ID
                || str_starts_with($resourceId, 'mcp_group_')
            ) {
                continue;
            }
            if (!isset($toolByAclId[$resourceId])) {
                // ACL declared by a module that no longer ships its tool — skip
                // silently; runtime gate is the source of truth either way.
                continue;
            }
            if (!$this->aclChecker->isAllowed($admin, $resourceId)) {
                throw new LocalizedException(
                    __('The selected admin user\'s role does not allow %1.', [$resourceId])
                );
            }
            $toolNames[] = $toolByAclId[$resourceId];
        }

        return array_values(array_unique($toolNames));
    }

    /**
     * @param array<string, mixed> $raw
     * @return void
     */
    private function preserveFormData(array $raw): void
    {
        $resourceIds = [];
        if (isset($raw['resource']) && is_array($raw['resource'])) {
            foreach ($raw['resource'] as $rid) {
                if (is_string($rid) && trim($rid) !== '') {
                    $resourceIds[] = trim($rid);
                }
            }
        }

        $toolByAclId = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $toolByAclId[$tool->getAclResource()] = $tool->getName();
        }
        $toolNames = [];
        foreach ($resourceIds as $rid) {
            if (isset($toolByAclId[$rid])) {
                $toolNames[] = $toolByAclId[$rid];
            }
        }

        $this->formDataPersistence->set([
            'admin_user_id' => $raw['admin_user_id'] ?? '',
            'name' => $raw['name'] ?? '',
            'expires_at' => $raw['expires_at'] ?? '',
            'allow_writes' => $raw['allow_writes'] ?? '1',
            'all_resources' => $raw['all_resources'] ?? '1',
            'resource' => $resourceIds,
            'scopes_tool_names' => $toolNames,
        ]);
    }
}
