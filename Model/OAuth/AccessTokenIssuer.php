<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Config\Source\ReauthBehavior;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use RuntimeException;

/**
 * Mints an OAuth 2.1 access + refresh token pair. The access token is a row in
 * `magebit_mcp_token` with an `oauth_client_id` linkback so it flows through the
 * same authenticator as CLI bearers. Plaintexts are returned once; only HMACs persist.
 */
class AccessTokenIssuer
{
    /**
     * @param TokenFactory $tokenFactory
     * @param TokenRepository $tokenRepository
     * @param RefreshTokenFactory $refreshTokenFactory
     * @param RefreshTokenRepository $refreshTokenRepository
     * @param TokenGenerator $tokenGenerator
     * @param TokenHasher $tokenHasher
     * @param ModuleConfig $config
     * @param ToolGrantResolver $toolGrantResolver
     */
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly TokenRepository $tokenRepository,
        private readonly RefreshTokenFactory $refreshTokenFactory,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly ModuleConfig $config,
        private readonly ToolGrantResolver $toolGrantResolver
    ) {
    }

    /**
     * @param int $oauthClientId
     * @param string $oauthClientName
     * @param int $adminUserId
     * @param bool $allowWrites
     * @param array<int, string>|null $toolNames
     * @param int|null $parentRefreshTokenId
     * @return IssuedTokenPair
     */
    public function issue(
        int $oauthClientId,
        string $oauthClientName,
        int $adminUserId,
        bool $allowWrites,
        ?array $toolNames = null,
        ?int $parentRefreshTokenId = null
    ): IssuedTokenPair {
        // Refresh-token rotation already revokes the predecessor — re-applying the policy
        // here would nuke the token being replaced and break long-lived sessions.
        if ($parentRefreshTokenId === null) {
            $this->applyReauthPolicy($oauthClientId, $adminUserId);
        }

        $accessTtl = $this->config->getOAuthAccessTokenLifetime();
        $refreshTtlDays = $this->config->getOAuthRefreshTokenLifetimeDays();

        $accessPlain = $this->tokenGenerator->generate();
        $accessHash = $this->tokenHasher->hash($accessPlain);
        // UTC stamp to match Token::isExpired()'s UTC parsing regardless of server TZ.
        $accessExpiresAt = gmdate('Y-m-d H:i:s', time() + $accessTtl);

        $normalizedTools = ($toolNames === null || $toolNames === [])
            ? null
            : array_values($toolNames);

        $grantedScope = $normalizedTools === null
            ? null
            : ($this->toolGrantResolver->summarizeScope($normalizedTools) ?: null);

        $token = $this->tokenFactory->create();
        $token->setAdminUserId($adminUserId);
        // Cap the client-name slice so 'OAuth: <name>' fits in magebit_mcp_token.name (varchar 128).
        $token->setName(sprintf('OAuth: %s', mb_substr($oauthClientName, 0, 121)));
        $token->setTokenHash($accessHash);
        $token->setAllowWrites($allowWrites);
        $token->setExpiresAt($accessExpiresAt);
        $token->setOAuthClientId($oauthClientId);
        $token->setScopes($normalizedTools);

        $this->tokenRepository->save($token);
        $accessTokenId = $token->getId();
        if ($accessTokenId === null) {
            throw new RuntimeException('Failed to persist OAuth access token row.');
        }

        $refreshPlain = $this->tokenGenerator->generate();
        $refreshHash = $this->tokenHasher->hash($refreshPlain);
        $refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + ($refreshTtlDays * 86400));

        $refresh = $this->refreshTokenFactory->create();
        $refresh->setTokenHash($refreshHash);
        $refresh->setOAuthClientId($oauthClientId);
        $refresh->setAccessTokenId($accessTokenId);
        $refresh->setParentRefreshTokenId($parentRefreshTokenId);
        $refresh->setExpiresAt($refreshExpiresAt);

        $this->refreshTokenRepository->save($refresh);
        $refreshTokenId = $refresh->getId();
        if ($refreshTokenId === null) {
            throw new RuntimeException('Failed to persist OAuth refresh token row.');
        }

        return new IssuedTokenPair(
            accessToken: $accessPlain,
            accessTokenId: $accessTokenId,
            expiresIn: $accessTtl,
            refreshToken: $refreshPlain,
            refreshTokenId: $refreshTokenId,
            grantedScope: $grantedScope
        );
    }

    /**
     * @param int $oauthClientId
     * @param int $adminUserId
     * @return void
     * @throws OAuthException When policy is REJECT and an active token exists.
     */
    private function applyReauthPolicy(int $oauthClientId, int $adminUserId): void
    {
        $behavior = $this->config->getOAuthReauthBehavior();
        if ($behavior === ReauthBehavior::ALLOW_MULTIPLE) {
            return;
        }

        $active = $this->tokenRepository->getActiveByClientAndAdmin($oauthClientId, $adminUserId);
        if ($active === []) {
            return;
        }

        if ($behavior === ReauthBehavior::REJECT) {
            throw new OAuthException(
                'invalid_grant',
                'An active token already exists for this client and admin. Revoke it before requesting a new one.'
            );
        }

        // ROTATE — revoke marks `revoked_at`; refresh tokens whose access_token row is
        // revoked become unusable through the rotator's reuse-detection.
        foreach ($active as $token) {
            $id = $token->getId();
            if ($id !== null) {
                $this->tokenRepository->revoke($id);
            }
        }
    }
}
