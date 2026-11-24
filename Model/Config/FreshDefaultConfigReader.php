<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads one default-scope config value as it is now, bypassing the config held in process memory.
 *
 * A long-lived queue consumer keeps Magento's scope config (and the module's own memoised readers) from the
 * moment it started, so a value an admin saved since then is not seen. Cleaning the config cache instead would
 * flush it store-wide on every call. Precedence follows Magento's for the default scope:
 *
 * 1. the environment variable `CONFIG__DEFAULT__<PATH>` (read through Magento's own {@see SettingChecker});
 * 2. an env.php / config.php lock (`system/default/<path>`);
 * 3. the `core_config_data` row (`scope = default`, `scope_id = 0`);
 * 4. the shipped config.xml default ({@see Initial}), which cannot change at run time. The merged scope config
 *    is not used here: it still holds a row that was deleted ("Use system value") after the process started.
 */
class FreshDefaultConfigReader
{
    public const TABLE = 'core_config_data';

    /**
     * @param SettingChecker $settingChecker
     * @param DeploymentConfig $deploymentConfig
     * @param ResourceConnection $resourceConnection
     * @param Initial $initialConfig
     */
    public function __construct(
        private readonly SettingChecker $settingChecker,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly Initial $initialConfig
    ) {
    }

    /**
     * @param string $path
     * @return string|null
     */
    public function get(string $path): ?string
    {
        $fromEnvironment = $this->settingChecker->getPlaceholderValue(
            $path,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if ($fromEnvironment !== null) {
            return $this->toString($fromEnvironment);
        }

        $locked = $this->deploymentConfig->get('system/' . ScopeConfigInterface::SCOPE_TYPE_DEFAULT . '/' . $path);
        if ($locked !== null) {
            return $this->toString($locked);
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['value'])
            ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->where('scope_id = ?', 0)
            ->where('path = ?', $path)
            ->limit(1);
        $row = $connection->fetchRow($select);
        if (is_array($row)) {
            return $this->toString($row['value'] ?? null);
        }

        return $this->shippedDefault($path);
    }

    /**
     * The same truthiness as {@see ScopeConfigInterface::isSetFlag()}.
     *
     * @param string $path
     * @return bool
     */
    public function isSetFlag(string $path): bool
    {
        return (bool) $this->get($path);
    }

    /**
     * @param string $path
     * @return string|null
     */
    private function shippedDefault(string $path): ?string
    {
        $node = $this->initialConfig->getData(ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        foreach (explode('/', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $this->toString($node);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function toString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
