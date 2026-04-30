<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Model\OAuth\ResourceModel\AuthCode as AuthCodeResource;
use Magebit\Mcp\Model\OAuth\ResourceModel\AuthCode\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * CRUD + lookup service for {@see AuthCode} entities.
 *
 * {@see self::markUsed()} and {@see self::deleteExpired()} bypass the model layer to keep the
 * one-shot redemption write atomic and to keep the cron purge a single statement.
 */
class AuthCodeRepository
{
    /**
     * @param AuthCodeFactory $authCodeFactory
     * @param AuthCodeResource $resource
     * @param CollectionFactory $collectionFactory
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly AuthCodeFactory $authCodeFactory,
        private readonly AuthCodeResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param AuthCode $code
     * @return AuthCode
     */
    public function save(AuthCode $code): AuthCode
    {
        $this->resource->save($code);
        return $code;
    }

    /**
     * @param int $id
     * @return AuthCode
     * @throws NoSuchEntityException
     */
    public function getById(int $id): AuthCode
    {
        $code = $this->authCodeFactory->create();
        $this->resource->load($code, $id);
        if ($code->getId() === null) {
            throw NoSuchEntityException::singleField('id', $id);
        }
        return $code;
    }

    /**
     * Looks an authorization code up by its HMAC hash. The plaintext is never logged or returned
     * in the exception message — code material is treated as a credential.
     *
     * @param string $hash
     * @return AuthCode
     * @throws NoSuchEntityException
     */
    public function getByHash(string $hash): AuthCode
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('code_hash', ['eq' => $hash]);
        /** @var AuthCode $code */
        $code = $collection->getFirstItem();
        if ($code->getId() === null) {
            // Hash omitted so the wrapping exception doesn't leak credential material.
            throw NoSuchEntityException::singleField('code_hash', '<redacted>');
        }
        return $code;
    }

    /**
     * Compare-and-swap stamp of `used_at` so a concurrent redemption can't reuse the same code.
     * Returns `true` when this caller flipped the row from unused to used; `false` when another
     * caller already won the race (the row exists but its `used_at` is non-null). Callers MUST
     * treat `false` as `invalid_grant` per OAuth 2.1 §4.1.3 single-use guarantee.
     *
     * @param int $id
     * @return bool
     */
    public function markUsed(int $id): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_auth_code');
        $rows = (int) $connection->update(
            $table,
            ['used_at' => gmdate('Y-m-d H:i:s')],
            ['id = ?' => $id, 'used_at IS NULL']
        );
        return $rows === 1;
    }

    /**
     * Deletes authorization codes whose expiry was more than 24 hours ago. Single-bound predicate
     * so the index on `expires_at` is exercised. The 24-hour grace window keeps used and unused
     * expired rows around long enough that audit-log correlations can still resolve them.
     *
     * @return int Rows deleted.
     */
    public function deleteExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_auth_code');
        return $connection->delete($table, 'expires_at < NOW() - INTERVAL 1 DAY');
    }
}
