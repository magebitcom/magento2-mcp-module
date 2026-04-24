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
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * Handles POST `magebit_mcp/token/save` — mints a bearer for the selected
 * admin user and drops the plaintext into a *separate* session key so the
 * listing page shows it exactly once.
 *
 * The plaintext DOES NOT go through the standard `messageManager` success
 * stream: those messages can be replayed by reloads / iframes / concurrent
 * tabs, which we don't want for a secret. The listing page pops the value
 * from its dedicated session bucket after one render and clears it.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_tokens';

    /** @see \Magebit\Mcp\Controller\Adminhtml\Token\Index for the consumer. */
    public const SESSION_KEY_PLAINTEXT = 'magebit_mcp_new_token';

    /**
     * @param Context $context
     * @param AdminUserLookup $adminUserLookup
     * @param TokenFactory $tokenFactory
     * @param TokenGenerator $tokenGenerator
     * @param TokenHasher $tokenHasher
     * @param TokenRepository $tokenRepository
     */
    public function __construct(
        Context $context,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly TokenFactory $tokenFactory,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);

        $raw = $this->getRequest()->getPostValue();
        if (!is_array($raw) || $raw === []) {
            $this->messageManager->addErrorMessage((string) __('Missing form payload.'));
            return $redirect->setPath('*/*/new');
        }
        if (isset($raw['data']) && is_array($raw['data'])) {
            $raw = $raw['data'];
        }

        try {
            $adminUserId = $this->extractAdminUserId($raw);
            $name = $this->extractName($raw);
            $allowWrites = (bool) ($raw['allow_writes'] ?? 0);
            $expiresAt = $this->extractExpiresAt($raw);
            $scopes = $this->extractScopes($raw);

            $admin = $this->adminUserLookup->getById($adminUserId);
            if ((int) $admin->getIsActive() !== 1) {
                throw new \RuntimeException('Selected admin user is inactive.');
            }
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage((string) __('Admin user not found.'));
            return $redirect->setPath('*/*/new');
        } catch (Throwable $e) {
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
        $token->setScopes($scopes === [] ? null : $scopes);
        if ($expiresAt !== null) {
            $token->setExpiresAt($expiresAt);
        }

        try {
            $this->tokenRepository->save($token);
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to save connection: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/new');
        }

        // Stash the plaintext so the listing controller can display it once.
        $this->_session->setData(self::SESSION_KEY_PLAINTEXT, [
            'token_id' => (int) $token->getId(),
            'plaintext' => $plaintext,
        ]);

        $this->messageManager->addSuccessMessage(
            (string) __('Connection "%1" created — bearer token shown on the next screen.', $name)
        );

        return $redirect->setPath('*/*/index');
    }

    /**
     * Coerce `data[admin_user_id]` into a positive int or fail.
     *
     * @param array $raw
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
     * Validate + trim the form's Name field, capping at 128 chars.
     *
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
     * Parse the optional expires_at input into a UTC "Y-m-d H:i:s" string.
     *
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
     * Normalize the scopes multiselect value to a deduped array of tool names.
     *
     * @param array $raw
     * @phpstan-param array<string, mixed> $raw
     * @return array<int, string>
     */
    private function extractScopes(array $raw): array
    {
        $value = $raw['scopes'] ?? [];
        if (is_string($value)) {
            // UI multiselect sometimes serializes an empty picker as '' and a
            // one-option pick as a comma-joined string. Handle both.
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values(array_unique($out));
    }
}
