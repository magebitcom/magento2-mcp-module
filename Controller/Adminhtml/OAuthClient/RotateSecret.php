<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\OAuthClient;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientRepository;
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
use Magento\User\Model\User;
use Throwable;

/**
 * POST `magebit_mcp/oauthclient/rotatesecret` — regenerates the client secret.
 * `revoke_tokens=1` also revokes every live token from this client.
 */
class RotateSecret extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_oauth_clients';

    /**
     * @param Context $context
     * @param ClientRepository $clientRepository
     * @param ClientCredentialIssuer $issuer
     * @param TokenRepository $tokenRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ClientRepository $clientRepository,
        private readonly ClientCredentialIssuer $issuer,
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

        $idRaw = $request->getParam('id');
        $id = is_scalar($idRaw) ? (int) $idRaw : 0;
        if ($id <= 0) {
            $this->messageManager->addErrorMessage((string) __('Missing client id.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $client = $this->clientRepository->getById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('OAuth client #%1 no longer exists.', $id)
            );
            return $redirect->setPath('*/*/index');
        }

        $revokeTokens = $request->getParam('revoke_tokens') === '1';
        $clientPk = (int) $client->getId();

        try {
            $plaintext = $this->issuer->rotateSecret($client);
        } catch (Throwable $e) {
            $this->logger->error('OAuth client secret rotation failed.', [
                'client_id' => $client->getClientId(),
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Failed to rotate the client secret: %1', $e->getMessage())
            );
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }

        $tokensRevoked = 0;
        if ($revokeTokens) {
            try {
                $tokensRevoked = $this->tokenRepository->revokeAllForClient($clientPk);
            } catch (Throwable $e) {
                // Rotation already succeeded — surface as a warning so the new secret still reaches the operator.
                $this->logger->warning('OAuth client token revocation failed after rotation.', [
                    'client_id' => $client->getClientId(),
                    'exception' => $e,
                ]);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Secret rotated, but revoking live tokens failed: %1. Retry via the listing'
                        . ' or revoke individual tokens manually.',
                        $e->getMessage()
                    )
                );
            }
        }

        $this->logger->info('OAuth client secret rotated.', [
            'client_id' => $client->getClientId(),
            'admin_user_id' => $this->resolveAdminUserId(),
            'tokens_revoked' => $tokensRevoked,
            'revoke_tokens_requested' => $revokeTokens,
        ]);

        $this->messageManager->addSuccessMessage(
            (string) __('Client secret rotated. Copy the new secret below — it will not be shown again.')
        );

        return $this->renderRotatedCredentialsPage($client->getName(), $client->getClientId(), $plaintext);
    }

    /**
     * @param string $name
     * @param string $clientId
     * @param string $plaintext
     * @return Page
     */
    private function renderRotatedCredentialsPage(string $name, string $clientId, string $plaintext): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Magebit_Mcp::mcp_oauth_clients');
        $page->getConfig()->getTitle()->prepend((string) __('OAuth Client Secret Rotated'));
        $page->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $page->setHeader('Pragma', 'no-cache', true);

        $block = $page->getLayout()->getBlock('mcp.oauthclient.created');
        if ($block instanceof AbstractBlock) {
            $block->setData('name', $name);
            $block->setData('client_id', $clientId);
            $block->setData('client_secret', $plaintext);
            $block->setData('is_rotation', true);
        }
        return $page;
    }

    /**
     * @return int|null
     */
    private function resolveAdminUserId(): ?int
    {
        $user = $this->_auth->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $raw = $user->getId();
        return is_scalar($raw) ? (int) $raw : null;
    }
}
