<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use JsonException;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Short-lived store for the public→admin authorize handoff. The public authorize
 * endpoint writes validated params keyed by a random nonce, then redirects the
 * browser to the adminhtml consent controller. A dedicated table is used because
 * frontend and adminhtml session cookies are independently scoped. Rows are purged
 * by {@see \Magebit\Mcp\Cron\PurgeOAuthCodes}.
 */
class AuthorizeHandoffStorage
{
    /**
     * Long enough for an admin login (with 2FA), short enough that a stolen URL
     * cannot be replayed from a proxy log.
     */
    public const DEFAULT_TTL_SECONDS = 60;

    private const TABLE = 'magebit_mcp_oauth_authorize_handoff';

    /**
     * @param ResourceConnection $resourceConnection
     * @param TokenHasher $hasher
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TokenHasher $hasher,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Persist `$params` keyed by HMAC(`$noncePlaintext`). Only the hash is stored.
     *
     * @param string $noncePlaintext
     * @param array<string, mixed> $params
     * @param int $ttlSeconds
     * @return void
     */
    public function store(string $noncePlaintext, array $params, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        try {
            $paramsJson = json_encode($params, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to encode OAuth handoff params.', ['exception' => $e->getMessage()]);
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->insert($table, [
            'nonce_hash' => $this->hasher->hash($noncePlaintext),
            'params_json' => $paramsJson,
            'expires_at' => $this->expiryTimestamp($ttlSeconds),
        ]);
    }

    /**
     * Read params without deleting the row, so a page refresh on the admin GET
     * still resolves. Null when the nonce is unknown or expired.
     *
     * @param string $noncePlaintext
     * @return array<string, mixed>|null
     */
    public function peek(string $noncePlaintext): ?array
    {
        $row = $this->fetchActiveRow($noncePlaintext);
        return $row === null ? null : $this->decodeParams($row);
    }

    /**
     * One-shot redemption. Deletes the row before returning so the same nonce
     * cannot be replayed even within the TTL window.
     *
     * @param string $noncePlaintext
     * @return array<string, mixed>|null
     */
    public function consume(string $noncePlaintext): ?array
    {
        $row = $this->fetchActiveRow($noncePlaintext, deleteWhenFound: true);
        return $row === null ? null : $this->decodeParams($row);
    }

    /**
     * @param string $noncePlaintext
     * @param bool $deleteWhenFound
     * @return array{id: int, params_json: string, expires_at: string}|null
     */
    private function fetchActiveRow(string $noncePlaintext, bool $deleteWhenFound = false): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $hash = $this->hasher->hash($noncePlaintext);

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['id', 'params_json', 'expires_at'])
                ->where('nonce_hash = ?', $hash)
        );
        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }

        if ($deleteWhenFound) {
            $connection->delete($table, ['id = ?' => (int) $row['id']]);
        }

        if (isset($row['expires_at']) && (string) $row['expires_at'] < $this->now()) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'params_json' => isset($row['params_json']) ? (string) $row['params_json'] : '',
            'expires_at' => isset($row['expires_at']) ? (string) $row['expires_at'] : '',
        ];
    }

    /**
     * @param array{id: int, params_json: string, expires_at: string} $row
     * @return array<string, mixed>|null
     */
    private function decodeParams(array $row): ?array
    {
        try {
            $decoded = json_decode($row['params_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to decode OAuth handoff params.', ['exception' => $e->getMessage()]);
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        return (int) $connection->delete($table, ['expires_at < ?' => $this->now()]);
    }

    /**
     * @return string
     */
    private function now(): string
    {
        return $this->dateTime->gmtDate();
    }

    /**
     * @param int $ttlSeconds
     * @return string
     */
    private function expiryTimestamp(int $ttlSeconds): string
    {
        return $this->dateTime->gmtDate(null, time() + max(1, $ttlSeconds));
    }
}
