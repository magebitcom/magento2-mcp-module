<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Model\Config\Source\ReauthBehavior;

/**
 * End-to-end coverage for `magebit_mcp/oauth/reauth_behavior` and the per-client
 * `disabled` flag over real HTTP. Issuer branches are covered by unit tests.
 */
class TokenReauthPolicyTest extends TokenEndpointTestCase
{
    private const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * Default behavior: a second auth-code exchange for the same (client, admin)
     * leaves the first access token live alongside the new one.
     *
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoConfigFixture default/magebit_mcp/oauth/reauth_behavior allow_multiple
     */
    public function testAllowMultipleLeavesPriorTokenIntact(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('reauth-test-allow', ['https://example.com/cb']);

        try {
            $first = $this->mintAccessToken((int) $client['client']->getId(), $admin, $client);
            self::assertNotSame('', $first);

            // Hit the same /mcp endpoint to confirm the first token is live BEFORE
            // we issue the second — protects against unrelated regressions.
            $beforeSecond = $this->callToolsListWith($first);
            self::assertSame(200, $beforeSecond['status'], 'First access token must be live before re-auth.');

            $second = $this->mintAccessToken((int) $client['client']->getId(), $admin, $client);
            self::assertNotSame('', $second);
            self::assertNotSame($first, $second, 'Each issuance must yield a distinct access token.');

            // ALLOW_MULTIPLE — first token still authenticates.
            $afterSecond = $this->callToolsListWith($first);
            self::assertSame(200, $afterSecond['status'], 'allow_multiple must keep the prior token live.');

            // …and the second works too.
            $secondCheck = $this->callToolsListWith($second);
            self::assertSame(200, $secondCheck['status']);
        } finally {
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * `rotate` revokes every active prior token for the (client, admin) pair
     * before minting the new one. The HTTP path proves the revocation reaches
     * TokenAuthenticator.
     *
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoConfigFixture default/magebit_mcp/oauth/reauth_behavior rotate
     */
    public function testRotateRevokesPriorTokenOnReauth(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('reauth-test-rotate', ['https://example.com/cb']);

        try {
            $first = $this->mintAccessToken((int) $client['client']->getId(), $admin, $client);
            $before = $this->callToolsListWith($first);
            self::assertSame(200, $before['status'], 'First token must work before rotation.');

            $second = $this->mintAccessToken((int) $client['client']->getId(), $admin, $client);
            self::assertNotSame($first, $second);

            // The first token must now be rejected by /mcp — rotation revoked it.
            $rotated = $this->callToolsListWith($first);
            self::assertSame(
                401,
                $rotated['status'],
                'rotate must invalidate the prior access token at the auth layer.'
            );

            // …and the new one authenticates.
            $stillLive = $this->callToolsListWith($second);
            self::assertSame(200, $stillLive['status']);
        } finally {
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * `reject` fails the auth-code exchange with `invalid_grant` if the same
     * (client, admin) pair already has a live token. The prior token stays
     * usable — proves we abort BEFORE writing anything.
     *
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     * @magentoConfigFixture default/magebit_mcp/oauth/reauth_behavior reject
     */
    public function testRejectBlocksReauthWhilePriorTokenLive(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('reauth-test-reject', ['https://example.com/cb']);

        try {
            $first = $this->mintAccessToken((int) $client['client']->getId(), $admin, $client);
            self::assertNotSame('', $first);

            // Second exchange must fail.
            $secondAuthCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );
            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $secondAuthCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(400, $response['status'], 'reject policy must surface 400 on the token endpoint.');
            self::assertSame('invalid_grant', $response['payload']['error'] ?? null);

            // …and the prior token still works.
            $stillLive = $this->callToolsListWith($first);
            self::assertSame(200, $stillLive['status'], 'reject must not touch the prior token.');
        } finally {
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * A disabled client must not be able to mint new tokens. The exchange
     * fails with `invalid_client` — same shape an unknown client would
     * produce, so we don't leak existence-but-disabled.
     *
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testDisabledClientCannotExchangeAuthCode(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('reauth-test-disabled', ['https://example.com/cb']);
        $clientId = (int) $client['client']->getId();

        try {
            // Issue an auth code first (still allowed — disable happens after).
            $authCode = AuthCodeFixture::issueFor(
                $clientId,
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            // Toggle disabled on the row.
            $row = $client['client'];
            $row->setDisabled(true);
            $this->objectManager()->get(\Magebit\Mcp\Model\OAuth\ClientRepository::class)->save($row);

            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(401, $response['status']);
            self::assertSame('invalid_client', $response['payload']['error'] ?? null);
        } finally {
            ClientFixture::delete($clientId);
        }
    }

    /**
     * @phpstan-param array{client_id: string, client_secret: string, client: object} $client
     */
    private function mintAccessToken(int $clientId, int $adminUserId, array $client): string
    {
        $authCode = AuthCodeFixture::issueFor(
            $clientId,
            $adminUserId,
            'https://example.com/cb',
            self::RFC_CHALLENGE
        );

        $response = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $authCode['code'],
            'code_verifier' => self::RFC_VERIFIER,
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]);
        self::assertSame(200, $response['status'], 'Helper exchange must succeed.');
        $accessToken = $response['payload']['access_token'] ?? '';
        self::assertIsString($accessToken);
        return $accessToken;
    }

    /**
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    private function callToolsListWith(string $accessToken): array
    {
        return $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]
        );
    }
}
