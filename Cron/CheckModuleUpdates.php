<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Cron;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\ModuleUpdate\InstalledPackageProvider;
use Magebit\Mcp\Model\ModuleUpdate\PackagistClient;
use Magebit\Mcp\Model\ModuleUpdate\UpdateSummaryStorage;
use Magebit\Mcp\Model\ModuleUpdate\VersionComparator;
use Throwable;

/**
 * Daily check for newer Magebit MCP releases; caches the outdated set for the admin banner.
 *
 * Gated by store config; when disabled it clears the cached result so a stale banner can't linger.
 */
class CheckModuleUpdates
{
    /**
     * @param ModuleConfig $config
     * @param InstalledPackageProvider $packages
     * @param PackagistClient $packagist
     * @param VersionComparator $versionComparator
     * @param UpdateSummaryStorage $storage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly InstalledPackageProvider $packages,
        private readonly PackagistClient $packagist,
        private readonly VersionComparator $versionComparator,
        private readonly UpdateSummaryStorage $storage,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        try {
            if (!$this->config->isModuleUpdateCheckEnabled()) {
                $this->storage->clear();
                return;
            }

            $outdated = [];
            foreach ($this->packages->getInstalledPackages() as $package => $installed) {
                $latest = $this->packagist->getLatestStableVersion($package);
                if ($latest === null) {
                    continue;
                }
                if ($this->versionComparator->isNewer($latest, $installed)) {
                    $outdated[] = ['package' => $package, 'installed' => $installed, 'latest' => $latest];
                }
            }

            $this->storage->save($outdated);

            if ($outdated !== []) {
                $this->logger->info('MCP update check found outdated modules.', ['count' => count($outdated)]);
            }
        } catch (Throwable $e) {
            $this->logger->error('MCP update check failed.', ['exception' => $e]);
        }
    }
}
