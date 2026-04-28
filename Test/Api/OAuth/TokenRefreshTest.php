<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

/**
 * `POST /mcp/oauth/token` — `refresh_token` grant.
 *
 * Covers the security-load-bearing branches of Task 20:
 *   1. Happy path → 200, fresh access+refresh, old access becomes 401, old
 *      refresh becomes `invalid_grant` (revoke-on-use rotation).
 *   2. Refresh presented with the wrong client's credentials → 400
 *      `invalid_grant` (cross-client binding).
 *   3. Missing `refresh_token` parameter → 400 `invalid_request`.
 *
 * Bootstraps an initial access+refresh pair by walking the auth-code grant
 * end-to-end, then exercises the refresh endpoint against the real HTTP path.
 *
 * Shared cURL/header/admin-user helpers live in {@see TokenEndpointTestCase}.
 */
class TokenRefreshTest extends TokenEndpointTestCase
{
    private const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testRefreshHappyPath(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            $first = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(200, $first['status'], 'Auth-code redemption should succeed.');
            self::assertArrayHasKey('access_token', $first['payload']);
            self::assertArrayHasKey('refresh_token', $first['payload']);
            $oldAccess = $first['payload']['access_token'];
            $oldRefresh = $first['payload']['refresh_token'];
            self::assertIsString($oldAccess);
            self::assertIsString($oldRefresh);
            self::assertNotSame('', $oldAccess);
            self::assertNotSame('', $oldRefresh);

            // Rotate.
            $second = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $oldRefresh,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(200, $second['status']);
            self::assertSame('Bearer', $second['payload']['token_type'] ?? null);
            self::assertSame(3600, $second['payload']['expires_in'] ?? null);
            self::assertArrayHasKey('access_token', $second['payload']);
            self::assertArrayHasKey('refresh_token', $second['payload']);
            $newAccess = $second['payload']['access_token'];
            $newRefresh = $second['payload']['refresh_token'];
            self::assertIsString($newAccess);
            self::assertIsString($newRefresh);
            self::assertNotSame($oldAccess, $newAccess, 'Access token must rotate.');
            self::assertNotSame($oldRefresh, $newRefresh, 'Refresh token must rotate.');

            // Old access token is revoked — /mcp must answer 401.
            $oldList = $this->request(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['headers' => ['Authorization' => 'Bearer ' . $oldAccess]]
            );
            self::assertSame(401, $oldList['status'], 'Old access token must be revoked after rotation.');

            // New access token still works — proves the fresh pair is live.
            $newList = $this->request(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['headers' => ['Authorization' => 'Bearer ' . $newAccess]]
            );
            self::assertSame(200, $newList['status'], 'New access token should be accepted by /mcp.');

            // Re-using the old refresh fails — proves revoke-on-use semantics.
            $reuse = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $oldRefresh,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(400, $reuse['status']);
            self::assertSame('invalid_grant', $reuse['payload']['error'] ?? null);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testRefreshFromDifferentClientReturnsInvalidGrant(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $clientA = ClientFixture::issue('Client A', ['https://a.example.com/cb']);
        $clientB = ClientFixture::issue('Client B', ['https://b.example.com/cb']);
        try {
            $authCodeA = AuthCodeFixture::issueFor(
                (int) $clientA['client']->getId(),
                $admin,
                'https://a.example.com/cb',
                self::RFC_CHALLENGE
            );

            $first = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCodeA['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://a.example.com/cb',
                'client_id' => $clientA['client_id'],
                'client_secret' => $clientA['client_secret'],
            ]);
            self::assertSame(200, $first['status']);
            self::assertArrayHasKey('refresh_token', $first['payload']);
            $refreshA = $first['payload']['refresh_token'];
            self::assertIsString($refreshA);
            self::assertNotSame('', $refreshA);

            // Client B presents A's refresh token. Must fail with invalid_grant
            // (not invalid_client — the request is authenticated, but the bound
            // client doesn't match the refresh-token row).
            $cross = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshA,
                'client_id' => $clientB['client_id'],
                'client_secret' => $clientB['client_secret'],
            ]);
            self::assertSame(400, $cross['status']);
            self::assertSame('invalid_grant', $cross['payload']['error'] ?? null);
        } finally {
            $idA = $clientA['client']->getId();
            if ($idA !== null) {
                ClientFixture::delete((int) $idA);
            }
            $idB = $clientB['client']->getId();
            if ($idB !== null) {
                ClientFixture::delete((int) $idB);
            }
        }
    }

    public function testRefreshMissingTokenReturnsInvalidRequest(): void
    {
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $response = $this->postToken([
                'grant_type' => 'refresh_token',
                // No `refresh_token` parameter.
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);

            self::assertSame(400, $response['status']);
            self::assertSame('invalid_request', $response['payload']['error'] ?? null);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }
}
