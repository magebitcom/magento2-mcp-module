<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ModuleUpdate;

use Magebit\Mcp\Api\LoggerInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Throwable;

/**
 * Discovers the installed Magebit MCP package family for update checking.
 *
 * Derived dynamically so future satellites are covered without code changes. Packages
 * whose version can't be resolved (mounted into app/code, not Composer-installed) are skipped.
 */
class InstalledPackageProvider
{
    private const MODULE_PREFIX = 'Magebit_Mcp';

    /**
     * @param FullModuleList $moduleList
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param File $fileDriver
     * @param ComposerVersionResolver $versionResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FullModuleList $moduleList,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly File $fileDriver,
        private readonly ComposerVersionResolver $versionResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Composer package name => installed pretty version, for resolvable MCP modules.
     *
     * @return array<string, string>
     */
    public function getInstalledPackages(): array
    {
        $packages = [];

        foreach ($this->moduleList->getNames() as $moduleName) {
            if (!str_starts_with($moduleName, self::MODULE_PREFIX)) {
                continue;
            }

            try {
                $packageName = $this->readPackageName($moduleName);
                if ($packageName === null) {
                    continue;
                }

                $version = $this->versionResolver->getInstalledVersion($packageName);
                if ($version === null) {
                    continue;
                }

                $packages[$packageName] = $version;
            } catch (Throwable $e) {
                $this->logger->warning(
                    'MCP update check could not inspect module, skipping.',
                    ['module' => $moduleName, 'exception' => $e]
                );
            }
        }

        return $packages;
    }

    /**
     * @param string $moduleName
     * @return ?string
     */
    private function readPackageName(string $moduleName): ?string
    {
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        if ($path === null) {
            return null;
        }

        $composerFile = $path . '/composer.json';
        if (!$this->fileDriver->isExists($composerFile)) {
            return null;
        }

        $contents = $this->fileDriver->fileGetContents($composerFile);
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        $name = $decoded['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }
}
