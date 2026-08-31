<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Auth;

use Magebit\Mcp\Model\Auth\AdminRoleLookup;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory;
use Magento\Authorization\Model\Role;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminRoleLookupTest extends TestCase
{
    /**
     * @phpstan-var CollectionFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CollectionFactory&MockObject $collectionFactory;

    private AdminRoleLookup $lookup;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->lookup = new AdminRoleLookup($this->collectionFactory);
    }

    public function testGetIdByNameReturnsTheMatchingRoleId(): void
    {
        $this->stubCollection([$this->makeRole(4, 'Support')]);

        self::assertSame(4, $this->lookup->getIdByName('Support'));
    }

    public function testGetIdByNameThrowsWhenTheRoleIsUnknown(): void
    {
        $this->stubCollection([]);

        $this->expectException(NoSuchEntityException::class);
        $this->lookup->getIdByName('Nope');
    }

    public function testGetAllNamesListsEveryRole(): void
    {
        $this->stubCollection([$this->makeRole(9, 'Ops'), $this->makeRole(4, 'Support')]);

        self::assertSame(['Ops', 'Support'], $this->lookup->getAllNames());
    }

    /**
     * @param array<int, Role&MockObject> $roles
     * @return void
     */
    private function stubCollection(array $roles): void
    {
        $collection = $this->createMock(RoleCollection::class);
        $collection->method('setRolesFilter')->willReturn($collection);
        $collection->method('addFieldToFilter')->willReturn($collection);
        $collection->method('setOrder')->willReturn($collection);
        $collection->method('getItems')->willReturn($roles);
        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * @param int $id
     * @param string $name
     * @return Role&MockObject
     */
    private function makeRole(int $id, string $name): Role&MockObject
    {
        $role = $this->createMock(Role::class);
        $role->method('getId')->willReturn($id);
        $role->method('getData')->with('role_name')->willReturn($name);
        return $role;
    }
}
