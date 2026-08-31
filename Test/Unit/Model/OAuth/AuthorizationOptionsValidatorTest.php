<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\AuthorizationOptions;
use Magebit\Mcp\Model\OAuth\AuthorizationOptionsValidator;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthorizationOptionsValidatorTest extends TestCase
{
    /**
     * @phpstan-var UserCollectionFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private UserCollectionFactory&MockObject $userCollectionFactory;

    private AuthorizationOptionsValidator $validator;

    protected function setUp(): void
    {
        $this->userCollectionFactory = $this->createMock(UserCollectionFactory::class);
        $this->validator = new AuthorizationOptionsValidator($this->userCollectionFactory);
    }

    public function testPersonalDefaultsAreValid(): void
    {
        $this->userCollectionFactory->expects(self::never())->method('create');

        self::assertNull($this->validator->validate(AuthorizationOptions::personalDefault()));
    }

    public function testSharedModeWithoutServiceAdminIsRejected(): void
    {
        $error = $this->validator->validate(new AuthorizationOptions(
            mode: AuthMode::SHARED,
            serviceAdminUserId: null,
            allowedAdminUserIds: [],
            allowedAdminRoleIds: [],
            disabled: false
        ));

        self::assertNotNull($error);
        self::assertStringContainsString('Shared mode requires', $error);
    }

    public function testSharedModeWithInactiveServiceAdminIsRejected(): void
    {
        $this->stubActiveLookups([7 => false]);

        $error = $this->validator->validate(new AuthorizationOptions(
            mode: AuthMode::SHARED,
            serviceAdminUserId: 7,
            allowedAdminUserIds: [],
            allowedAdminRoleIds: [],
            disabled: false
        ));

        self::assertNotNull($error);
        self::assertStringContainsString('active admin', $error);
    }

    public function testSharedModeWithActiveServiceAdminIsValid(): void
    {
        $this->stubActiveLookups([7 => true]);

        self::assertNull($this->validator->validate(new AuthorizationOptions(
            mode: AuthMode::SHARED,
            serviceAdminUserId: 7,
            allowedAdminUserIds: [],
            allowedAdminRoleIds: [],
            disabled: false
        )));
    }

    public function testInactiveWhitelistedAdminIsRejected(): void
    {
        $this->stubActiveLookups([3 => true, 4 => false]);

        $error = $this->validator->validate(new AuthorizationOptions(
            mode: AuthMode::PERSONAL,
            serviceAdminUserId: null,
            allowedAdminUserIds: [3, 4],
            allowedAdminRoleIds: [],
            disabled: false
        ));

        self::assertNotNull($error);
        self::assertStringContainsString('Allowed Admin Users', $error);
    }

    public function testSharedModeIgnoresWhitelistsThatWouldBeCleared(): void
    {
        // applyAuthorizationOptions() wipes the whitelists in shared mode, so an
        // inactive id there must not block the save.
        $this->stubActiveLookups([7 => true]);

        self::assertNull($this->validator->validate(new AuthorizationOptions(
            mode: AuthMode::SHARED,
            serviceAdminUserId: 7,
            allowedAdminUserIds: [999],
            allowedAdminRoleIds: [],
            disabled: false
        )));
    }

    /**
     * @param array<int, bool> $activeByUserId
     * @return void
     */
    private function stubActiveLookups(array $activeByUserId): void
    {
        $this->userCollectionFactory->method('create')->willReturnCallback(
            function () use (&$activeByUserId): UserCollection {
                $collection = $this->createMock(UserCollection::class);
                $requested = null;
                $collection->method('addFieldToFilter')->willReturnCallback(
                    static function (string $field, mixed $condition) use ($collection, &$requested) {
                        if ($field === 'user_id' && is_array($condition) && is_scalar($condition['eq'] ?? null)) {
                            $requested = (int) $condition['eq'];
                        }
                        return $collection;
                    }
                );
                $collection->method('setPageSize')->willReturn($collection);
                $collection->method('getSize')->willReturnCallback(
                    static function () use (&$requested, &$activeByUserId): int {
                        return ($activeByUserId[$requested] ?? false) ? 1 : 0;
                    }
                );
                return $collection;
            }
        );
    }
}
