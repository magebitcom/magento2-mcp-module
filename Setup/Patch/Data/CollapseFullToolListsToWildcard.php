<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Setup\Patch\Data;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Collapses clients whose `allowed_tools_json` lists every currently-registered
 * tool down to the wildcard sentinel `["*"]`, so pre-existing "tick everything"
 * clients automatically inherit tools added by satellite modules later.
 */
class CollapseFullToolListsToWildcard implements DataPatchInterface
{
    private const CLIENT_TABLE = 'magebit_mcp_oauth_client';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * @return $this
     */
    public function apply(): self
    {
        $registryToolNames = array_keys($this->toolRegistry->all());
        if ($registryToolNames === []) {
            return $this;
        }
        sort($registryToolNames);
        $registrySignature = implode("\0", $registryToolNames);

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::CLIENT_TABLE);

        $select = $connection->select()->from($table, [
            ClientInterface::ID,
            ClientInterface::ALLOWED_TOOLS_JSON,
        ]);

        $rows = $connection->fetchAll($select);
        $wildcardJson = json_encode([ClientInterface::ALLOW_ALL_TOOLS_SENTINEL]);
        if (!is_string($wildcardJson)) {
            return $this;
        }

        foreach ($rows as $row) {
            $rawId = $row[ClientInterface::ID] ?? null;
            $rawJson = $row[ClientInterface::ALLOWED_TOOLS_JSON] ?? null;
            if (!is_scalar($rawId) || !is_string($rawJson) || $rawJson === '') {
                continue;
            }
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                continue;
            }
            $names = array_values(array_filter(
                $decoded,
                static fn (mixed $v): bool => is_string($v) && $v !== ''
            ));
            // Already wildcard or empty? Leave alone.
            if ($names === [] || $names === [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL]) {
                continue;
            }
            $unique = array_values(array_unique($names));
            sort($unique);
            if (implode("\0", $unique) !== $registrySignature) {
                continue;
            }
            $connection->update(
                $table,
                [ClientInterface::ALLOWED_TOOLS_JSON => $wildcardJson],
                [ClientInterface::ID . ' = ?' => (int) $rawId]
            );
        }

        return $this;
    }

    /**
     * @return array<int, class-string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
