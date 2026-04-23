<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Wraps the admin user loaders the MCP module actually needs.
 *
 * Magento ships no ServiceContract for admin users — `\Magento\User\Model\User`
 * / `UserFactory` / `ResourceModel\User\CollectionFactory` are the only handles.
 * Every MCP call site that needs admin data goes through this class so the
 * service-contract exception is documented in exactly one place, and the
 * collection-level batching in {@see self::listByIds()} doesn't get duplicated
 * in each consumer.
 */
class AdminUserLookup
{
    /**
     * @param UserFactory $userFactory
     * @param UserCollectionFactory $userCollectionFactory
     */
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * Load an admin user by primary key.
     *
     * @param int $id
     * @return User
     * @throws NoSuchEntityException
     */
    public function getById(int $id): User
    {
        $user = $this->userFactory->create();
        // @phpstan-ignore-next-line magento.serviceContract — no ServiceContract for admin users.
        $user->load($id);
        if ($user->getId() === null) {
            throw NoSuchEntityException::singleField('user_id', $id);
        }
        return $user;
    }

    /**
     * Load an admin user by username.
     *
     * @param string $username
     * @return User
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
     * Batch-load a set of admin users via a single collection query.
     *
     * @param array $ids
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
