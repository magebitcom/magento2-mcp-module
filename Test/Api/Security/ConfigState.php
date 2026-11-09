<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Sets default-scope config for a test and puts the old values back afterwards.
 * Reads go straight to `core_config_data`, because the config cache in the test process can be stale.
 */
final class ConfigState
{
    private const SCOPE = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
    private const SCOPE_ID = 0;

    /**
     * Saved state per path: did a row exist, and what was its value.
     *
     * @var array<string, array{exists: bool, value: string|null}>
     */
    private static array $remembered = [];

    /**
     * Remember the current state of a path. Only the first call per path counts.
     *
     * @param string $path
     * @return void
     */
    public static function remember(string $path): void
    {
        if (array_key_exists($path, self::$remembered)) {
            return;
        }
        self::$remembered[$path] = [
            'exists' => self::hasRow($path),
            'value' => self::read($path),
        ];
    }

    /**
     * Remember the path, then save the new value at default scope.
     *
     * @param string $path
     * @param string $value
     * @return void
     */
    public static function set(string $path, string $value): void
    {
        self::remember($path);
        self::writer()->save($path, $value, self::SCOPE, self::SCOPE_ID);
        self::cleanCache();
    }

    /**
     * Put back every remembered value, or delete rows that did not exist before.
     *
     * @return void
     */
    public static function restoreAll(): void
    {
        if (self::$remembered === []) {
            return;
        }
        foreach (self::$remembered as $path => $state) {
            if ($state['exists']) {
                // A NULL value is kept as NULL, same as before the test.
                // @phpstan-ignore-next-line argument.type (the writer stores NULL as NULL)
                self::writer()->save($path, $state['value'], self::SCOPE, self::SCOPE_ID);
            } else {
                self::writer()->delete($path, self::SCOPE, self::SCOPE_ID);
            }
        }
        self::$remembered = [];
        self::cleanCache();
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function hasRow(string $path): bool
    {
        return self::fetchRow($path) !== null;
    }

    /**
     * Value stored in `core_config_data`, or null when there is no row or the value is NULL.
     *
     * @param string $path
     * @return string|null
     */
    public static function read(string $path): ?string
    {
        $row = self::fetchRow($path);
        if ($row === null || !is_scalar($row['value'])) {
            return null;
        }

        return (string) $row['value'];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private static function fetchRow(string $path): ?array
    {
        /** @var ResourceConnection $resource */
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $select = $connection->select()
            ->from($resource->getTableName('core_config_data'), ['value'])
            ->where('path = ?', $path)
            ->where('scope = ?', self::SCOPE)
            ->where('scope_id = ?', self::SCOPE_ID);
        /** @var array<string, mixed>|false $row */
        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * @return WriterInterface
     */
    private static function writer(): WriterInterface
    {
        /** @var WriterInterface $writer */
        $writer = Bootstrap::getObjectManager()->get(WriterInterface::class);

        return $writer;
    }

    /**
     * @return void
     */
    private static function cleanCache(): void
    {
        /** @var TypeListInterface $typeList */
        $typeList = Bootstrap::getObjectManager()->get(TypeListInterface::class);
        $typeList->cleanType('config');
    }
}
