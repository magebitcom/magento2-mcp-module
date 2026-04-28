<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Model\OAuth\AuthCodeIssuer;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Programmatic OAuth authorization-code issuance for api-functional tests.
 *
 * Composes the same {@see AuthCodeIssuer} the consent submit handler uses so
 * the row written here is byte-identical to one minted via the interactive
 * flow — same hash format, same PKCE challenge column, same expiry stamping.
 *
 * Returns the plaintext code to the caller; it is never persisted, matching
 * production behaviour.
 */
final class AuthCodeFixture
{
    /**
     * Issue a fresh auth code for an existing client + admin combination.
     * Returns the plaintext code (and the client+admin context for assertion convenience).
     *
     * @phpstan-return array{code: string, oauth_client_id: int, admin_user_id: int}
     */
    public static function issueFor(
        int $oauthClientId,
        int $adminUserId,
        string $redirectUri,
        string $codeChallenge,
        ?string $scope = null
    ): array {
        $om = Bootstrap::getObjectManager();
        /** @var AuthCodeIssuer $issuer */
        $issuer = $om->get(AuthCodeIssuer::class);
        $code = $issuer->issue(
            $oauthClientId,
            $adminUserId,
            $redirectUri,
            $codeChallenge,
            'S256',
            $scope
        );
        return [
            'code' => $code,
            'oauth_client_id' => $oauthClientId,
            'admin_user_id' => $adminUserId,
        ];
    }
}
