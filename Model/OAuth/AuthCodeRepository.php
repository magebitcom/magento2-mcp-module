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
final class AuthCodeRepository
{
    public function __construct(
        private readonly AuthCodeFactory $authCodeFactory,
        private readonly AuthCodeResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function save(AuthCode $code): AuthCode
    {
        $this->resource->save($code);
        return $code;
    }

    /**
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
     * Atomically stamps `used_at` on a code so a concurrent redemption can't reuse it. Written in
     * UTC to match {@see AuthCode::isExpired()}'s comparison.
     */
    public function markUsed(int $id): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_auth_code');
        $connection->update(
            $table,
            ['used_at' => gmdate('Y-m-d H:i:s')],
            ['id = ?' => $id]
        );
    }

    /**
     * Deletes expired and stale-used authorization codes. Used codes are kept for 24 hours so
     * audit-log correlations can still resolve the row before it disappears.
     *
     * @return int Rows deleted.
     */
    public function deleteExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_oauth_auth_code');
        return $connection->delete(
            $table,
            'expires_at < NOW() OR (used_at IS NOT NULL AND used_at < NOW() - INTERVAL 1 DAY)'
        );
    }
}
