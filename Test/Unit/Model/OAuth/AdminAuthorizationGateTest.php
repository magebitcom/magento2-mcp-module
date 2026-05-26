<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\AdminAuthorizationDecision;
use Magebit\Mcp\Model\OAuth\AdminAuthorizationGate;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\Client;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class AdminAuthorizationGateTest extends TestCase
{
    private AdminAuthorizationGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AdminAuthorizationGate();
    }

    public function testDisabledClientShortCircuits(): void
    {
        $client = $this->mockClient(disabled: true);
        $admin = $this->mockAdmin(42, [1]);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_CLIENT_DISABLED,
            $this->gate->decide($client, $admin)
        );
    }

    public function testNullAdminIsDeniedRegardlessOfMode(): void
    {
        $client = $this->mockClient();
        self::assertSame(
            AdminAuthorizationDecision::DENIED_NO_ADMIN,
            $this->gate->decide($client, null)
        );
    }

    public function testAdminWithoutIdIsDenied(): void
    {
        $client = $this->mockClient();
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(null);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_NO_ADMIN,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeEmptyWhitelistsAllow(): void
    {
        $client = $this->mockClient();
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeUserIdInWhitelistAllows(): void
    {
        $client = $this->mockClient(allowedUserIds: [42, 99]);
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeRoleIdInWhitelistAllows(): void
    {
        $client = $this->mockClient(allowedRoleIds: [3, 7]);
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeUnionOfListsLetsEitherMatchAllow(): void
    {
        $client = $this->mockClient(allowedUserIds: [42], allowedRoleIds: [99]);
        // Admin in role 99 but with non-whitelisted user id → still allowed (role match).
        $admin = $this->mockAdmin(7, [99]);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeNeitherMatchDenies(): void
    {
        $client = $this->mockClient(allowedUserIds: [10, 20], allowedRoleIds: [3]);
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_NOT_WHITELISTED,
            $this->gate->decide($client, $admin)
        );
    }

    public function testPersonalModeAdminWithNoRolesIsDeniedWhenRoleListIsSet(): void
    {
        $client = $this->mockClient(allowedRoleIds: [3]);
        $admin = $this->mockAdmin(42, []);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_NOT_WHITELISTED,
            $this->gate->decide($client, $admin)
        );
    }

    public function testSharedModeMatchingServiceAdminAllows(): void
    {
        $client = $this->mockClient(authMode: AuthMode::SHARED, serviceAdminUserId: 42);
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testSharedModeNonMatchingAdminIsDenied(): void
    {
        $client = $this->mockClient(authMode: AuthMode::SHARED, serviceAdminUserId: 42);
        $admin = $this->mockAdmin(7, [7]);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_SHARED_MISMATCH,
            $this->gate->decide($client, $admin)
        );
    }

    public function testSharedModeWithoutServiceAdminIsMisconfigured(): void
    {
        $client = $this->mockClient(authMode: AuthMode::SHARED, serviceAdminUserId: null);
        $admin = $this->mockAdmin(42, [7]);
        self::assertSame(
            AdminAuthorizationDecision::MISCONFIGURED_NO_SERVICE_ADMIN,
            $this->gate->decide($client, $admin)
        );
    }

    public function testSharedModeIgnoresPersonalWhitelists(): void
    {
        // Whitelists are set but irrelevant in shared mode — only service_admin_user_id matters.
        $client = $this->mockClient(
            authMode: AuthMode::SHARED,
            serviceAdminUserId: 42,
            allowedUserIds: [7],
            allowedRoleIds: [99]
        );
        $admin = $this->mockAdmin(7, [99]);
        self::assertSame(
            AdminAuthorizationDecision::DENIED_SHARED_MISMATCH,
            $this->gate->decide($client, $admin)
        );
    }

    public function testRoleIdsCoercedFromStringsForLegacyMagentoReturns(): void
    {
        // Magento's User::getRoles() historically returned strings on some adapters.
        $client = $this->mockClient(allowedRoleIds: [7]);
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(42);
        $admin->method('getRoles')->willReturn(['7']);
        self::assertSame(
            AdminAuthorizationDecision::ALLOW,
            $this->gate->decide($client, $admin)
        );
    }

    public function testDecisionHelpers(): void
    {
        // Quick smoke for the enum's helper methods so the controllers can rely on them.
        self::assertTrue(AdminAuthorizationDecision::ALLOW->isAllowed());
        self::assertFalse(AdminAuthorizationDecision::DENIED_NOT_WHITELISTED->isAllowed());

        self::assertSame('access_denied', AdminAuthorizationDecision::DENIED_SHARED_MISMATCH->oauthError());
        self::assertSame('server_error', AdminAuthorizationDecision::MISCONFIGURED_NO_SERVICE_ADMIN->oauthError());
        self::assertNotSame('', AdminAuthorizationDecision::DENIED_NOT_WHITELISTED->description());
    }

    /**
     * @param AuthMode $authMode
     * @param int|null $serviceAdminUserId
     * @param array<int, int> $allowedUserIds
     * @param array<int, int> $allowedRoleIds
     * @param bool $disabled
     * @return Client
     */
    private function mockClient(
        AuthMode $authMode = AuthMode::PERSONAL,
        ?int $serviceAdminUserId = null,
        array $allowedUserIds = [],
        array $allowedRoleIds = [],
        bool $disabled = false
    ): Client {
        $client = $this->createMock(Client::class);
        $client->method('isDisabled')->willReturn($disabled);
        $client->method('getAuthMode')->willReturn($authMode);
        $client->method('getServiceAdminUserId')->willReturn($serviceAdminUserId);
        $client->method('getAllowedAdminUserIds')->willReturn($allowedUserIds);
        $client->method('getAllowedAdminRoleIds')->willReturn($allowedRoleIds);
        return $client;
    }

    /**
     * @param int $userId
     * @param array<int, int|string> $roleIds
     * @return User
     */
    private function mockAdmin(int $userId, array $roleIds): User
    {
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn($userId);
        $admin->method('getRoles')->willReturn($roleIds);
        return $admin;
    }
}
