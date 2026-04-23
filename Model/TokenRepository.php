<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
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
 * {@see self::getByHash()} is the hot path used by the MCP authenticator on
 * every request — relies on the UNIQUE index on `token_hash` for O(1) lookup.
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
            // Column name intentionally omitted — don't leak schema detail into
            // logs of the wrapping UnauthorizedException chain.
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
     * Idempotent — returns the token regardless of its prior revocation state.
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
     * Lightweight last-used update — direct UPDATE, no model round-trip.
     * Safe to call on every successful authentication.
     */
    public function touchLastUsed(int $id): void
    {
        $connection = $this->resource->getConnection();
        if ($connection === false) {
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
     * @param array<int|string, mixed> $items
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
