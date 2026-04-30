<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Revoke-on-use refresh-token rotation per OAuth 2.1 §6.1.
 *
 * Rotation is gated by a compare-and-swap on `revoked_at`: only the caller that flips the
 * presented refresh from active to revoked gets to mint the successor pair. A racing caller
 * (legitimate retry or attacker replay) sees the row already revoked, which is the §6.1
 * reuse-detection signal — at that point the entire chain rotated from the reused refresh
 * is revoked and the client is forced to re-authorize.
 */
class RefreshTokenRotator
{
    /**
     * @param TokenHasher $tokenHasher
     * @param RefreshTokenRepository $refreshTokenRepository
     * @param TokenRepository $tokenRepository
     * @param ClientRepository $clientRepository
     * @param AccessTokenIssuer $accessTokenIssuer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TokenHasher $tokenHasher,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly AccessTokenIssuer $accessTokenIssuer,
        private readonly LoggerInterface $logger
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

        $storedId = (int) $stored->getId();

        if ($stored->isExpired()) {
            throw new OAuthException('invalid_grant', 'Refresh token is expired.');
        }
        if ($stored->isRevoked()) {
            // Already-revoked refresh presented again → §6.1 reuse-detected. Either an
            // attacker replayed, or the legitimate client retried after a successful rotate
            // race lost. Either way: revoke the chain rooted at this token and demand
            // re-authorization.
            $this->revokeChain($storedId);
            throw new OAuthException('invalid_grant', 'Refresh token reuse detected.');
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

        // CAS revoke. Lost race → another caller already rotated this refresh: the row they
        // minted is downstream, so revoke the chain and signal §6.1 reuse to the caller.
        if (!$this->refreshTokenRepository->revoke($storedId)) {
            $this->revokeChain($storedId);
            throw new OAuthException('invalid_grant', 'Refresh token reuse detected.');
        }
        $this->tokenRepository->revoke((int) $oldAccessToken->getId());

        // Preserve the original grant across rotation. The OAuth-protocol scope summary
        // is recomputed by the issuer from the tool list, so we only need to forward
        // the per-tool allowlist + write flag here.
        return $this->accessTokenIssuer->issue(
            oauthClientId: $oauthClientId,
            oauthClientName: $client->getName(),
            adminUserId: $oldAccessToken->getAdminUserId(),
            allowWrites: $oldAccessToken->getAllowWrites(),
            toolNames: $oldAccessToken->getScopes(),
            parentRefreshTokenId: $storedId
        );
    }

    /**
     * Walks the rotation chain rooted at the given refresh-token id and revokes every
     * descendant (refresh + paired access). The root itself was already revoked by the
     * caller. Bounded depth so a corrupted FK loop can't spin forever.
     *
     * @param int $rootId
     * @return void
     */
    private function revokeChain(int $rootId): void
    {
        $this->logger->warning(
            'OAuth refresh-token reuse detected; revoking rotation chain.',
            ['root_refresh_token_id' => $rootId]
        );

        $frontier = [$rootId];
        $depth = 0;
        while ($frontier !== [] && $depth < 32) {
            $next = [];
            foreach ($frontier as $parentId) {
                foreach ($this->refreshTokenRepository->getChildren($parentId) as $child) {
                    $childId = $child->getId();
                    if ($childId === null) {
                        continue;
                    }
                    $this->refreshTokenRepository->revoke($childId);
                    try {
                        $this->tokenRepository->revoke($child->getAccessTokenId());
                    } catch (NoSuchEntityException) {
                        // Access row already gone (cron purge or admin delete) — refresh row
                        // is still revoked, which is what matters for §6.1 enforcement.
                    }
                    $next[] = $childId;
                }
            }
            $frontier = $next;
            $depth++;
        }
    }
}
