<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Swaps a presented refresh-token plaintext for a fresh (access, refresh) pair using
 * revoke-on-use rotation, per OAuth 2.1 §6.1.
 *
 * Order of operations is deliberate: validate everything first, then revoke the old
 * pair, then mint the new one. A failure between revoke and issue leaves the caller
 * with a revoked refresh and no new pair — the correct security posture (forces a
 * fresh authorization rather than silently re-handing out the same refresh).
 *
 * Plaintext refresh tokens are hashed once on entry and the hash is never logged
 * or surfaced in exception messages — refresh material is treated as a credential.
 */
class RefreshTokenRotator
{
    /**
     * @param TokenHasher $tokenHasher
     * @param RefreshTokenRepository $refreshTokenRepository
     * @param TokenRepository $tokenRepository
     * @param ClientRepository $clientRepository
     * @param AccessTokenIssuer $accessTokenIssuer
     */
    public function __construct(
        private readonly TokenHasher $tokenHasher,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly AccessTokenIssuer $accessTokenIssuer
    ) {
    }

    /**
     * @param string $presentedRefreshToken
     * @param int $oauthClientId
     * @return IssuedTokenPair
     * @throws OAuthException
     */
    public function rotate(string $presentedRefreshToken, int $oauthClientId): IssuedTokenPair
    {
        $hash = $this->tokenHasher->hash($presentedRefreshToken);

        try {
            $stored = $this->refreshTokenRepository->getByHash($hash);
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_grant', 'Refresh token not recognized.');
        }

        if ($stored->getOAuthClientId() !== $oauthClientId) {
            throw new OAuthException('invalid_grant', 'Refresh token does not belong to this client.');
        }

        if (!$stored->isValid()) {
            throw new OAuthException('invalid_grant', 'Refresh token is revoked or expired.');
        }

        try {
            $oldAccessToken = $this->tokenRepository->getById($stored->getAccessTokenId());
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_grant', 'Linked access token row missing.');
        }

        try {
            $client = $this->clientRepository->getById($oauthClientId);
        } catch (NoSuchEntityException) {
            throw new OAuthException('invalid_client', 'Client no longer exists.', 401);
        }

        // Revoke old pair before issuing the new one. RefreshTokenRepository::revoke is
        // atomic-and-idempotent and TokenRepository::revoke is idempotent, so a concurrent
        // rotator racing on the same refresh can't end up with two live pairs.
        $this->refreshTokenRepository->revoke((int) $stored->getId());
        $this->tokenRepository->revoke((int) $oldAccessToken->getId());

        return $this->accessTokenIssuer->issue(
            oauthClientId: $oauthClientId,
            oauthClientName: $client->getName(),
            adminUserId: $oldAccessToken->getAdminUserId(),
            allowWrites: $oldAccessToken->getAllowWrites(),
            scope: null
        );
    }
}
