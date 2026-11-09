<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory;
use Magento\Authorization\Model\Role;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Resolves admin roles by name. The admin UI picks roles from a select, so ids
 * are enough there; the CLI takes names and needs this translation.
 */
class AdminRoleLookup
{
    /**
     * @param CollectionFactory $roleCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $roleCollectionFactory
    ) {
    }

    /**
     * @param string $roleName
     * @return int
     * @throws NoSuchEntityException
     */
    public function getIdByName(string $roleName): int
    {
        $collection = $this->roleCollectionFactory->create();
        $collection->setRolesFilter();
        $collection->addFieldToFilter('role_name', ['eq' => $roleName]);

        foreach ($this->narrowItems($collection->getItems()) as $id => $name) {
            if ($name === $roleName) {
                return $id;
            }
        }

        throw NoSuchEntityException::singleField('role_name', $roleName);
    }

    /**
     * @return array<int, string> Role names, alphabetical.
     */
    public function getAllNames(): array
    {
        $collection = $this->roleCollectionFactory->create();
        $collection->setRolesFilter();
        $collection->setOrder('role_name', 'ASC');

        return array_values($this->narrowItems($collection->getItems()));
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, string> Role id => role name.
     */
    private function narrowItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof Role) {
                continue;
            }
            $id = $item->getId();
            $name = $item->getData('role_name');
            if (!is_scalar($id) || (int) $id <= 0 || !is_scalar($name) || (string) $name === '') {
                continue;
            }
            $out[(int) $id] = (string) $name;
        }
        return $out;
    }
}
