<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model;

use Magebit\Mcp\Model\ResourceModel\Token as TokenResource;
use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use RuntimeException;

/**
 * CRUD + lookup service for {@see Token} entities.
 *
 * {@see self::getByHash()} relies on the UNIQUE index on `token_hash` for O(1) lookup.
 */
class TokenRepository
{
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly TokenResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime
    ) {
    }

    public function save(Token $token): Token
    {
        $this->resource->save($token);
        return $token;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): Token
    {
        $token = $this->tokenFactory->create();
        $this->resource->load($token, $id);
        if ($token->getId() === null) {
            throw NoSuchEntityException::singleField('id', $id);
        }
        return $token;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getByHash(string $hash): Token
    {
        $token = $this->tokenFactory->create();
        $this->resource->load($token, $hash, 'token_hash');
        if ($token->getId() === null) {
            // Column name omitted so the wrapping UnauthorizedException doesn't leak schema detail.
            throw NoSuchEntityException::singleField('bearer', '<redacted>');
        }
        return $token;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function deleteById(int $id): void
    {
        $token = $this->getById($id);
        $this->resource->delete($token);
    }

    /**
     * Idempotent.
     *
     * @throws NoSuchEntityException
     */
    public function revoke(int $id): Token
    {
        $token = $this->getById($id);
        if (!$token->isRevoked()) {
            $token->setRevokedAt($this->dateTime->gmtDate());
            $this->resource->save($token);
        }
        return $token;
    }

    /**
     * Direct UPDATE, no model round-trip. Safe to call on every successful authentication.
     */
    public function touchLastUsed(int $id): void
    {
        $connection = $this->resource->getConnection();
        if ($connection === false) {
            // AbstractDb::getConnection() returns AdapterInterface|false.
            throw new RuntimeException('Default DB connection unavailable.');
        }
        $connection->update(
            $this->resource->getMainTable(),
            ['last_used_at' => $this->dateTime->gmtDate()],
            $connection->quoteInto('id = ?', $id)
        );
    }

    /**
     * @return array<int, Token>
     */
    public function getByAdminUserId(int $adminUserId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('admin_user_id', ['eq' => $adminUserId]);
        return $this->narrowItems($collection->getItems());
    }

    /**
     * @return array<int, Token>
     */
    public function getList(): array
    {
        $collection = $this->collectionFactory->create();
        return $this->narrowItems($collection->getItems());
    }

    /**
     * @phpstan-param array<int, int> $ids
     * @return array<int, Token>
     */
    public function listByIds(array $ids): array
    {
        $filtered = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($filtered === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('id', ['in' => $filtered]);

        $out = [];
        foreach ($this->narrowItems($collection->getItems()) as $token) {
            $id = $token->getId();
            if ($id === null) {
                continue;
            }
            $out[$id] = $token;
        }
        return $out;
    }

    /**
     * @phpstan-param array<int|string, mixed> $items
     * @return array<int, Token>
     */
    private function narrowItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof Token) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
