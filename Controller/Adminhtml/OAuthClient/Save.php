<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use InvalidArgumentException;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Throwable;

/**
 * POST `magebit_mcp/oauthclient/save` — handles both create and edit. On
 * create, mints a fresh `client_id` + plaintext `client_secret` via
 * {@see ClientCredentialIssuer} and stashes the plaintext bundle in a
 * dedicated session key (NOT the messageManager success stream, which can
 * be replayed by reloads / iframes / concurrent tabs) so the listing page
 * can surface it exactly once. On edit, only `name` and `redirect_uris`
 * are updated — the secret is never editable; rotation = delete + create.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    /** @see \Magebit\Mcp\Controller\Adminhtml\OAuthClient\Index for the consumer. */
    public const SESSION_KEY_PLAINTEXT = 'magebit_mcp_new_oauth_client';

    public function __construct(
        Context $context,
        private readonly ClientCredentialIssuer $issuer,
        private readonly ClientRepository $clientRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $raw = $request->getPostValue();
        if (!is_array($raw) || $raw === []) {
            $this->messageManager->addErrorMessage((string) __('Missing form payload.'));
            return $redirect->setPath('*/*/index');
        }

        $name = $this->extractName($raw);
        $redirectUris = $this->extractRedirectUris($raw);

        $idRaw = $raw['id'] ?? 0;
        $editingId = is_scalar($idRaw) ? (int) $idRaw : 0;

        if ($editingId > 0) {
            return $this->handleUpdate($redirect, $editingId, $name, $redirectUris);
        }

        return $this->handleCreate($redirect, $name, $redirectUris);
    }

    /**
     * @param array<int, string> $redirectUris
     */
    private function handleUpdate(
        Redirect $redirect,
        int $editingId,
        string $name,
        array $redirectUris
    ): Redirect {
        try {
            $client = $this->clientRepository->getById($editingId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('OAuth client #%1 no longer exists.', $editingId)
            );
            return $redirect->setPath('*/*/index');
        }

        if ($name === '') {
            $this->messageManager->addErrorMessage((string) __('Name is required.'));
            return $redirect->setPath('*/*/edit', ['id' => $editingId]);
        }
        if ($redirectUris === []) {
            $this->messageManager->addErrorMessage(
                (string) __('At least one redirect URI is required.')
            );
            return $redirect->setPath('*/*/edit', ['id' => $editingId]);
        }

        try {
            $client->setName($name);
            $client->setRedirectUris($redirectUris);
            $this->clientRepository->save($client);
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to update OAuth client: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/edit', ['id' => $editingId]);
        }

        $this->messageManager->addSuccessMessage(
            (string) __('OAuth client "%1" updated.', $name)
        );
        return $redirect->setPath('*/*/index');
    }

    /**
     * @param array<int, string> $redirectUris
     */
    private function handleCreate(
        Redirect $redirect,
        string $name,
        array $redirectUris
    ): Redirect {
        try {
            $issued = $this->issuer->issue($name, $redirectUris);
        } catch (InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/new');
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Failed to create OAuth client: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/new');
        }

        $this->_session->setData(self::SESSION_KEY_PLAINTEXT, [
            'client_id' => $issued['client_id'],
            'client_secret' => $issued['client_secret'],
        ]);

        $this->messageManager->addSuccessMessage(
            (string) __('OAuth client "%1" created.', $name)
        );
        return $redirect->setPath('*/*/index');
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
     * Splits the textarea payload on any line ending and drops blank lines.
     * Per-URI scheme/host validation lives in {@see ClientCredentialIssuer}.
     *
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
}
