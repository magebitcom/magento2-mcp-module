<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Reads rows from the MCP audit log so tests can check what was recorded.
 */
final class AuditLogReader
{
    private const TABLE = 'magebit_mcp_audit_log';

    /**
     * Newest audit row for a tool (dotted name), or null when there is none.
     *
     * @param string $toolName
     * @param int|null $tokenId
     * @param int $afterId
     * @return array<string, mixed>|null
     */
    public static function latest(string $toolName, ?int $tokenId = null, int $afterId = 0): ?array
    {
        return self::fetchLatest('tool_name', $toolName, $tokenId, $afterId);
    }

    /**
     * Newest audit row for a JSON-RPC method, e.g. `(request)` for auth failures.
     *
     * @param string $method
     * @param int|null $tokenId
     * @param int $afterId
     * @return array<string, mixed>|null
     */
    public static function latestForMethod(string $method, ?int $tokenId = null, int $afterId = 0): ?array
    {
        return self::fetchLatest('method', $method, $tokenId, $afterId);
    }

    /**
     * Highest audit row id right now (0 when the table is empty).
     *
     * @return int
     */
    public static function maxId(): int
    {
        $connection = self::resource()->getConnection();
        $select = $connection->select()
            ->from(self::resource()->getTableName(self::TABLE), ['max_id' => 'MAX(id)']);

        return (int) $connection->fetchOne($select);
    }

    /**
     * @param string $column
     * @param string $value
     * @param int|null $tokenId
     * @param int $afterId
     * @return array<string, mixed>|null
     */
    private static function fetchLatest(string $column, string $value, ?int $tokenId, int $afterId): ?array
    {
        $connection = self::resource()->getConnection();
        $select = $connection->select()
            ->from(self::resource()->getTableName(self::TABLE))
            ->where($column . ' = ?', $value)
            ->where('id > ?', $afterId)
            ->order('id DESC')
            ->limit(1);
        if ($tokenId !== null) {
            $select->where('token_id = ?', $tokenId);
        }

        /** @var array<string, mixed>|false $row */
        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * @return ResourceConnection
     */
    private static function resource(): ResourceConnection
    {
        /** @var ResourceConnection $resource */
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);

        return $resource;
    }
}
