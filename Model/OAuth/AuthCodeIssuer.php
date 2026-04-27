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

/**
 * Mints one-shot OAuth 2.1 authorization codes that carry a PKCE challenge.
 *
 * Generates a 256-bit plaintext code, hashes it via the shared
 * {@see TokenHasher}, persists only the hash plus the PKCE challenge / method
 * (alongside the resolved client + admin user + redirect URI + optional scope
 * + expiry timestamp), and returns the plaintext to the caller exactly once.
 *
 * The plaintext is what the authorize-step redirect carries back to the OAuth
 * client; the DB only ever holds the hash, so a leaked DB dump cannot be used
 * to redeem an in-flight code without the install's crypt key.
 */
final class AuthCodeIssuer
{
    /**
     * @param AuthCodeFactory $authCodeFactory
     * @param AuthCodeRepository $authCodeRepository
     * @param TokenGenerator $tokenGenerator
     * @param TokenHasher $tokenHasher
     * @param ModuleConfig $config
     */
    public function __construct(
        private readonly AuthCodeFactory $authCodeFactory,
        private readonly AuthCodeRepository $authCodeRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * Issue a fresh authorization code and return the plaintext exactly once.
     *
     * @param int $oauthClientId
     * @param int $adminUserId
     * @param string $redirectUri
     * @param string $codeChallenge
     * @param string $codeChallengeMethod
     * @param string|null $scope
     * @return string Plaintext authorization code (never persisted, only hashed).
     */
    public function issue(
        int $oauthClientId,
        int $adminUserId,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $scope
    ): string {
        $plaintext = $this->tokenGenerator->generate();
        $hash = $this->tokenHasher->hash($plaintext);
        // UTC stamp to match AuthCode::isExpired()'s UTC parsing regardless of server TZ.
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->config->getOAuthAuthCodeLifetime());

        $authCode = $this->authCodeFactory->create();
        $authCode->setCodeHash($hash);
        $authCode->setOAuthClientId($oauthClientId);
        $authCode->setAdminUserId($adminUserId);
        $authCode->setRedirectUri($redirectUri);
        $authCode->setCodeChallenge($codeChallenge);
        $authCode->setCodeChallengeMethod($codeChallengeMethod);
        $authCode->setScope($scope);
        $authCode->setExpiresAt($expiresAt);

        $this->authCodeRepository->save($authCode);

        return $plaintext;
    }
}
