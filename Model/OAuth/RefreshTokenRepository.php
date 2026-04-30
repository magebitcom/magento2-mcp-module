<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Model\OAuth\ResourceModel\RefreshToken as RefreshTokenResource;
use Magebit\Mcp\Model\OAuth\ResourceModel\RefreshToken\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * CRUD + lookup service for {@see RefreshToken} entities.
 *
 * {@see self::revoke()} and {@see self::deleteExpired()} bypass the model layer to keep
 * the rotator's revoke-on-use write atomic and to keep the cron purge a single statement.
 */
class RefreshTokenRepository
{
    /**
     * @param RefreshTokenFactory $refreshTokenFactory
     * @param RefreshTokenResource $resource
     * @param CollectionFactory $collectionFactory
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly RefreshTokenFactory $refreshTokenFactory,
        private readonly RefreshTokenResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param RefreshToken $token
     * @return RefreshToken
     */
    public function save(RefreshToken $token): RefreshToken
    {
        $this->resource->save($token);
        return $token;
    }

    /**
     * @param int $id
     * @return RefreshToken
     * @throws NoSuchEntityException
     */
    public function getById(int $id): RefreshToken
    {
        $token = $this->refreshTokenFactory->create();
        $this->resource->load($token, $id);
        if ($token->getId() === null) {
            throw NoSuchEntityException::singleField('id', $id);
        }
        return $token;
    }

    /**
     * Looks a refresh token up by its HMAC hash. The plaintext is never logged or returned in the
     * exception message — refresh-token material is treated as a credential.
     *
     * @param string $hash
     * @return RefreshToken
     * @throws NoSuchEntityException
     */
    public function getByHash(string $hash): RefreshToken
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('token_hash', ['eq' => $hash]);
        /** @var RefreshToken $token */
        $token = $collection->getFirstItem();
        if ($token->getId() === null) {
            // Hash omitted so the wrapping exception doesn't leak credential material.
            throw NoSuchEntityException::singleField('token_hash', '<redacted>');
        }
        return $token;
    }

    /**
     * Compare-and-swap revoke. Returns `true` when this caller flipped `revoked_at` from NULL,
     * `false` when another caller had already revoked the row. Callers MUST treat `false` as
     * "lost the race" — for the rotator that means OAuth 2.1 §6.1 reuse-detected and the chain
     * must be unwound. Written in UTC to match {@see RefreshToken::isExpired()}'s comparison.
     *
     * @param int $id
     * @return bool
     */
    public function revoke(int $id): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_refresh_token');
        $rows = (int) $connection->update(
            $table,
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['id = ?' => $id, 'revoked_at IS NULL']
        );
        return $rows === 1;
    }

    /**
     * Returns the refresh tokens descended from the given parent — the row(s) the winning rotator
     * minted by rotating from `$parentId`. Used to walk the rotation chain when §6.1 reuse is
     * detected. Under healthy operation there is at most one child per parent, but the lookup is
     * tolerant in case a rare race ever lands two.
     *
     * @param int $parentId
     * @return array<int, RefreshToken>
     */
    public function getChildren(int $parentId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('parent_refresh_token_id', ['eq' => $parentId]);
        $out = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof RefreshToken) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Deletes refresh tokens whose expiry was more than 24 hours ago. Single-bound predicate so
     * the index on `expires_at` is exercised. The 24-hour grace window keeps used and unused
     * expired rows around long enough that audit-log correlations can still resolve them.
     *
     * @return int Rows deleted.
     */
    public function deleteExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_refresh_token');
        return $connection->delete($table, 'expires_at < NOW() - INTERVAL 1 DAY');
    }
}
