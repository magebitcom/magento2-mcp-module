<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use RuntimeException;

/**
 * Mints an OAuth 2.1 access-token + refresh-token pair.
 *
 * The access token is just a row in `magebit_mcp_token` carrying an
 * `oauth_client_id` linkback, so it flows through the same
 * {@see \Magebit\Mcp\Model\Auth\TokenAuthenticator} as CLI-issued bearers — no
 * separate auth path. The paired refresh token lives in
 * `magebit_mcp_oauth_refresh_token` and is what the rotator consumes to mint
 * the next pair.
 *
 * Both plaintexts are returned exactly once via {@see IssuedTokenPair}; the
 * DB only ever holds the HMAC hashes.
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
     */
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly TokenRepository $tokenRepository,
        private readonly RefreshTokenFactory $refreshTokenFactory,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * Issue a fresh access + refresh token pair for the given OAuth client / admin user.
     *
     * @param int $oauthClientId
     * @param string $oauthClientName
     * @param int $adminUserId
     * @param bool $allowWrites
     * @param string|null $scope
     * @return IssuedTokenPair
     */
    public function issue(
        int $oauthClientId,
        string $oauthClientName,
        int $adminUserId,
        bool $allowWrites,
        ?string $scope
    ): IssuedTokenPair {
        $accessTtl = $this->config->getOAuthAccessTokenLifetime();
        $refreshTtlDays = $this->config->getOAuthRefreshTokenLifetimeDays();

        $accessPlain = $this->tokenGenerator->generate();
        $accessHash = $this->tokenHasher->hash($accessPlain);
        // UTC stamp to match Token::isExpired()'s UTC parsing regardless of server TZ.
        $accessExpiresAt = gmdate('Y-m-d H:i:s', time() + $accessTtl);

        // TODO(future): map OAuth scope to scopes_json once a scope→tools registry exists.
        // For V1 the OAuth `scope` is captured but not narrowed to a tool allowlist —
        // `scopes_json` remains the fine-grained per-token tool allowlist, separate concern.
        unset($scope);

        $token = $this->tokenFactory->create();
        $token->setAdminUserId($adminUserId);
        $token->setName(sprintf('OAuth: %s', $oauthClientName));
        $token->setTokenHash($accessHash);
        $token->setAllowWrites($allowWrites);
        $token->setExpiresAt($accessExpiresAt);
        $token->setOAuthClientId($oauthClientId);

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
            refreshTokenId: $refreshTokenId
        );
    }
}
