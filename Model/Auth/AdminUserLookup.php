<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Wraps admin user loaders; Magento ships no ServiceContract for admin users.
 */
class AdminUserLookup
{
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): User
    {
        $user = $this->userFactory->create();
        // @phpstan-ignore-next-line magento.serviceContract — no ServiceContract exists.
        $user->load($id);
        if ($user->getId() === null) {
            throw NoSuchEntityException::singleField('user_id', $id);
        }
        return $user;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getByUsername(string $username): User
    {
        $user = $this->userFactory->create();
        $user->loadByUsername($username);
        $id = $user->getId();
        if (!is_scalar($id) || (int) $id === 0) {
            throw NoSuchEntityException::singleField('username', $username);
        }
        return $user;
    }

    /**
     * @phpstan-param array<int, int> $ids
     * @return array<int, User>
     */
    public function listByIds(array $ids): array
    {
        $filtered = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($filtered === []) {
            return [];
        }

        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('user_id', ['in' => $filtered]);
        $collection->addFieldToSelect(['user_id', 'username', 'firstname', 'lastname']);

        $out = [];
        foreach ($collection->getItems() as $user) {
            if (!$user instanceof User) {
                continue;
            }
            $rawId = $user->getData('user_id');
            if (!is_scalar($rawId)) {
                continue;
            }
            $out[(int) $rawId] = $user;
        }
        return $out;
    }
}
