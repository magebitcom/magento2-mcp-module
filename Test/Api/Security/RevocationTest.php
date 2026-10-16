<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Test\Api\OAuth\AuthCodeFixture;
use Magebit\Mcp\Test\Api\OAuth\ClientFixture;
use Magebit\Mcp\Test\Api\OAuth\TokenEndpointTestCase;
use Magebit\Mcp\Test\Api\TokenFixture;

/**
 * A revoked bearer, a disabled or deleted OAuth client and a replayed refresh token all stop working at once.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class RevocationTest extends TokenEndpointTestCase
{
    private const REDIRECT_URI = 'https://example.com/cb';

    /**
     * @return void
     */
    public function testRevokedBearerIsRefusedOnNextRequest(): void
    {
        $issued = TokenFixture::issueForAdmin('adminUser');
        $this->bearerToken = $issued['token'];
        $this->tokenRowId = $issued['id'];

        $this->assertJsonRpcSuccess($this->toolsList());

        /** @var TokenRepository $tokens */
        $tokens = $this->objectManager()->get(TokenRepository::class);
        $tokens->revoke($issued['id']);
        $before = AuditLogReader::maxId();

        $this->assertJsonRpcError($this->toolsList(), ErrorCode::UNAUTHORIZED->value, 401);
        $audit = AuditLogReader::latestForMethod('(request)', null, $before);
        self::assertNotNull($audit, 'Expected an audit row for the refused request.');
        self::assertSame('-32001', $audit['error_code'] ?? null);
    }

    /**
     * @return void
     */
    public function testDisabledOAuthClientRefusesItsLiveAccessToken(): void
    {
        $client = ClientFixture::issue('MCPSEC disable', [self::REDIRECT_URI]);
        try {
            $tokens = $this->redeem($client);
            self::assertSame(200, $this->listWith($tokens['access'])['status']);

            /** @var ClientRepository $repository */
            $repository = $this->objectManager()->get(ClientRepository::class);
            $client['client']->setDisabled(true);
            $repository->save($client['client']);

            self::assertSame(401, $this->listWith($tokens['access'])['status']);
        } finally {
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * @return void
     */
    public function testDeletedOAuthClientRefusesItsLiveAccessToken(): void
    {
        $client = ClientFixture::issue('MCPSEC delete', [self::REDIRECT_URI]);
        try {
            $tokens = $this->redeem($client);
            self::assertSame(200, $this->listWith($tokens['access'])['status']);

            /** @var ClientRepository $repository */
            $repository = $this->objectManager()->get(ClientRepository::class);
            $repository->deleteById((int) $client['client']->getId());

            self::assertSame(
                401,
                $this->listWith($tokens['access'])['status'],
                'An access token must stop working once its OAuth client is deleted.'
            );
        } finally {
            // No-op when the row is already gone.
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * @return void
     */
    public function testRefreshTokenReplayRevokesWholeChain(): void
    {
        $client = ClientFixture::issue('MCPSEC replay', [self::REDIRECT_URI]);
        try {
            $first = $this->redeem($client);

            $rotated = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $first['refresh'],
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(200, $rotated['status'], 'First rotation should succeed.');
            $newAccess = $rotated['payload']['access_token'] ?? null;
            $newRefresh = $rotated['payload']['refresh_token'] ?? null;
            self::assertIsString($newAccess);
            self::assertIsString($newRefresh);
            self::assertSame(200, $this->listWith($newAccess)['status']);

            // Replaying the already-used refresh token must be refused and burn the whole chain.
            $replay = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $first['refresh'],
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(400, $replay['status']);
            self::assertSame('invalid_grant', $replay['payload']['error'] ?? null);

            self::assertSame(401, $this->listWith($newAccess)['status'], 'Access token from the chain must die.');

            $child = $this->postToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => $newRefresh,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
            self::assertSame(400, $child['status']);
            self::assertSame('invalid_grant', $child['payload']['error'] ?? null);
        } finally {
            ClientFixture::delete((int) $client['client']->getId());
        }
    }

    /**
     * Run the authorization-code grant for the client and return the issued pair.
     *
     * @param array $client
     * @phpstan-param array{client: \Magebit\Mcp\Model\OAuth\Client, client_id: string, client_secret: string} $client
     * @return array{access: string, refresh: string}
     */
    private function redeem(array $client): array
    {
        $code = AuthCodeFixture::issueFor(
            (int) $client['client']->getId(),
            $this->loadAdminUserId('adminUser'),
            self::REDIRECT_URI,
            self::RFC_CHALLENGE
        );
        $response = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code['code'],
            'code_verifier' => self::RFC_VERIFIER,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]);
        self::assertSame(200, $response['status'], 'Auth-code redemption should succeed.');
        $access = $response['payload']['access_token'] ?? null;
        $refresh = $response['payload']['refresh_token'] ?? null;
        self::assertIsString($access);
        self::assertIsString($refresh);

        return ['access' => $access, 'refresh' => $refresh];
    }

    /**
     * @param string $access
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     * @return array
     */
    private function listWith(string $access): array
    {
        return $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['headers' => ['Authorization' => 'Bearer ' . $access]]
        );
    }
}
