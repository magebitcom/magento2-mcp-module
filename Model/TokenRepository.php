<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\OAuth\GrantDriftCache;
use Magebit\Mcp\Model\ResourceModel\Token as TokenResource;
use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use RuntimeException;

/**
 * CRUD + lookup service for {@see Token} entities.
 *
 * {@see self::getByHash()} relies on the UNIQUE index on `token_hash` for O(1) lookup. Every write that issues,
 * revokes or deletes a token clears the cached grant drift count ({@see GrantDriftCache}) here, so no caller has to;
 * the other invalidation point is {@see \Magebit\Mcp\Model\OAuth\ClientRepository::save()}.
 */
class TokenRepository
{
    /** Rows per page of {@see getActiveWithLiteralGrantPages()}. */
    public const DRIFT_PAGE_SIZE = 500;

    /** The columns {@see \Magebit\Mcp\Model\OAuth\GrantDrift::forToken()} reads. */
    public const DRIFT_COLUMNS = [
        TokenInterface::ID,
        TokenInterface::ADMIN_USER_ID,
        TokenInterface::OAUTH_CLIENT_ID,
        TokenInterface::SCOPES_JSON,
        TokenInterface::OFFERED_TOOLS_JSON,
        TokenInterface::ALLOW_WRITES,
        TokenInterface::EXPIRES_AT,
        TokenInterface::REVOKED_AT,
    ];

    /**
     * @param TokenFactory $tokenFactory
     * @param TokenResource $resource
     * @param CollectionFactory $collectionFactory
     * @param DateTime $dateTime
     * @param JobCanceller $jobCanceller
     * @param GrantDriftCache $grantDriftCache
     */
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly TokenResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime,
        private readonly JobCanceller $jobCanceller,
        private readonly GrantDriftCache $grantDriftCache
    ) {
    }

    /**
     * @param Token $token
     * @return Token
     */
    public function save(Token $token): Token
    {
        $this->resource->save($token);
        $this->grantDriftCache->invalidate();
        return $token;
    }

    /**
     * @param int $id
     * @return Token
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
     * @param string $hash
     * @return Token
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
     * @param int $id
     * @return void
     * @throws NoSuchEntityException
     */
    public function deleteById(int $id): void
    {
        $token = $this->getById($id);
        $this->resource->delete($token);
        $this->grantDriftCache->invalidate();
    }

    /**
     * Idempotent.
     *
     * @param int $id
     * @return Token
     * @throws NoSuchEntityException
     */
    public function revoke(int $id): Token
    {
        $token = $this->getById($id);
        if (!$token->isRevoked()) {
            $token->setRevokedAt($this->dateTime->gmtDate());
            $this->resource->save($token);
            $this->grantDriftCache->invalidate();
        }
        return $token;
    }

    /**
     * Revokes every live token of an OAuth client and cancels the client's open background jobs.
     *
     * Every client-wide path goes through here (client disable, delete, secret rotation with revoke). OAuth
     * refresh rotation never does: it revokes one token through {@see self::revoke()}, and the connection and
     * its jobs live on.
     *
     * @param int $oauthClientId
     * @return int Number of tokens revoked.
     */
    public function revokeAllForClient(int $oauthClientId): int
    {
        $connection = $this->resource->getConnection();
        if ($connection === false) {
            throw new RuntimeException('Default DB connection unavailable.');
        }
        $updated = $connection->update(
            $this->resource->getMainTable(),
            ['revoked_at' => $this->dateTime->gmtDate()],
            [
                $connection->quoteInto('oauth_client_id = ?', $oauthClientId),
                $connection->quoteIdentifier('revoked_at') . ' IS NULL',
            ]
        );
        if ($updated > 0) {
            $this->grantDriftCache->invalidate();
        }
        // Also when no token was live: a rotated-away token's jobs belong to the client's connections. Never throws.
        $this->jobCanceller->cancelForClient($oauthClientId);
        return (int) $updated;
    }

    /**
     * Direct UPDATE, no model round-trip. Safe to call on every successful authentication.
     *
     * @param int $id
     * @return void
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
     * @param int $adminUserId
     * @return array<int, Token>
     */
    public function getByAdminUserId(int $adminUserId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('admin_user_id', ['eq' => $adminUserId]);
        return $this->narrowItems($collection->getItems());
    }

    /**
     * Active tokens (not revoked, not expired) for the given OAuth-client + admin pair.
     *
     * @param int $oauthClientId
     * @param int $adminUserId
     * @return array<int, Token>
     */
    public function getActiveByClientAndAdmin(int $oauthClientId, int $adminUserId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('oauth_client_id', ['eq' => $oauthClientId]);
        $collection->addFieldToFilter('admin_user_id', ['eq' => $adminUserId]);
        $collection->addFieldToFilter('revoked_at', ['null' => true]);
        $items = $this->narrowItems($collection->getItems());
        return array_values(array_filter($items, static fn (Token $t): bool => !$t->isExpired()));
    }

    /**
     * Live tokens (not revoked, not expired) whose grant lists tools by name, of every admin and client, in pages of
     * {@see DRIFT_PAGE_SIZE}.
     *
     * Id-keyset paging (`id > last ORDER BY id LIMIT n`), so rows changing between pages never shift a page; each
     * row carries only {@see DRIFT_COLUMNS}, and each page's collection is cleared once the caller moves on.
     *
     * @return \Generator<int, list<Token>, mixed, void>
     */
    public function getActiveWithLiteralGrantPages(): \Generator
    {
        $lastId = 0;
        do {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('revoked_at', ['null' => true]);
            $collection->addFieldToFilter('scopes_json', ['notnull' => true]);
            $collection->addFieldToFilter('expires_at', [['null' => true], ['gt' => $this->dateTime->gmtDate()]]);
            $collection->addFieldToFilter(TokenInterface::ID, ['gt' => $lastId]);
            $collection->addFieldToSelect(self::DRIFT_COLUMNS);
            $collection->setOrder(TokenInterface::ID, Collection::SORT_ORDER_ASC);
            $collection->setPageSize(self::DRIFT_PAGE_SIZE);
            $items = $this->narrowItems($collection->getItems());
            $rows = count($items);
            if ($rows > 0) {
                $id = $items[$rows - 1]->getId();
                $lastId = is_scalar($id) ? (int) $id : $lastId;
                // Safety net for a clock edge between the query and here.
                yield array_values(array_filter($items, static fn (Token $t): bool => !$t->isExpired()));
            }
            $collection->clear();
            unset($collection, $items);
        } while ($rows === self::DRIFT_PAGE_SIZE);
    }

    /**
     * The newest live (not revoked, not expired) token of one admin's OAuth connection, or null.
     *
     * Used when a background job outlives the access token that created it: the job belongs to the connection,
     * whose current token then stands in for the deleted one.
     *
     * @param int $adminUserId
     * @param int $oauthClientId
     * @return Token|null
     */
    public function findNewestActiveForClient(int $adminUserId, int $oauthClientId): ?Token
    {
        $newest = null;
        foreach ($this->getActiveByClientAndAdmin($oauthClientId, $adminUserId) as $token) {
            if ($newest === null || (int) $token->getId() > (int) $newest->getId()) {
                $newest = $token;
            }
        }
        return $newest;
    }

    /**
     * The newest unrevoked token of one admin's OAuth connection, expired or not, or null.
     *
     * Used when the access token that created a background job was revoked: OAuth rotation revokes it and the
     * connection lives on in its successor, which an idle client may not have refreshed yet (access tokens live
     * an hour). Expiry is ignored, as it is for the creating token itself; only a revoke ends the connection.
     *
     * @param int $adminUserId
     * @param int $oauthClientId
     * @return Token|null
     */
    public function findNewestUnrevokedForClient(int $adminUserId, int $oauthClientId): ?Token
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('oauth_client_id', ['eq' => $oauthClientId]);
        $collection->addFieldToFilter('admin_user_id', ['eq' => $adminUserId]);
        $collection->addFieldToFilter('revoked_at', ['null' => true]);
        $collection->setOrder('id', 'DESC');
        $collection->setPageSize(1);
        $newest = null;
        foreach ($this->narrowItems($collection->getItems()) as $token) {
            if ($newest === null || (int) $token->getId() > (int) $newest->getId()) {
                $newest = $token;
            }
        }
        return $newest;
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
     * @param array<int, int> $ids
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
