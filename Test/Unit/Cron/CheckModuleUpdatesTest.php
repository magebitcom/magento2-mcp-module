<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Cron;

use Magebit\Mcp\Api\LoggerInterface;
use Composer\Semver\VersionParser;
use Magebit\Mcp\Cron\CheckModuleUpdates;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\ModuleUpdate\InstalledPackageProvider;
use Magebit\Mcp\Model\ModuleUpdate\PackagistClient;
use Magebit\Mcp\Model\ModuleUpdate\UpdateSummaryStorage;
use Magebit\Mcp\Model\ModuleUpdate\VersionComparator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckModuleUpdatesTest extends TestCase
{
    /**
     * @var ModuleConfig
     * @phpstan-var ModuleConfig&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ModuleConfig&MockObject $config;

    /**
     * @var InstalledPackageProvider
     * @phpstan-var InstalledPackageProvider&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private InstalledPackageProvider&MockObject $packages;

    /**
     * @var PackagistClient
     * @phpstan-var PackagistClient&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private PackagistClient&MockObject $packagist;

    /**
     * @var UpdateSummaryStorage
     * @phpstan-var UpdateSummaryStorage&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private UpdateSummaryStorage&MockObject $storage;

    /**
     * @var LoggerInterface
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    /**
     * @var CheckModuleUpdates
     */
    private CheckModuleUpdates $cron;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ModuleConfig::class);
        $this->packages = $this->createMock(InstalledPackageProvider::class);
        $this->packagist = $this->createMock(PackagistClient::class);
        $this->storage = $this->createMock(UpdateSummaryStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new CheckModuleUpdates(
            $this->config,
            $this->packages,
            $this->packagist,
            new VersionComparator(new VersionParser()),
            $this->storage,
            $this->logger
        );
    }

    public function testClearsAndExitsWhenDisabled(): void
    {
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(false);
        $this->storage->expects($this->once())->method('clear');
        $this->packages->expects($this->never())->method('getInstalledPackages');
        $this->packagist->expects($this->never())->method('getLatestStableVersion');
        $this->storage->expects($this->never())->method('save');

        $this->cron->execute();
    }

    public function testSavesOnlyOutdatedPackages(): void
    {
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->packages->method('getInstalledPackages')->willReturn([
            'magebitcom/magento2-mcp-module' => '1.0.0',       // outdated
            'magebitcom/magento2-mcp-order-tools' => '2.0.0',  // current
            'magebitcom/magento2-mcp-cms-tools' => '1.5.0',    // latest unknown, skipped
        ]);
        $this->packagist->method('getLatestStableVersion')->willReturnMap([
            ['magebitcom/magento2-mcp-module', '1.2.0'],
            ['magebitcom/magento2-mcp-order-tools', '2.0.0'],
            ['magebitcom/magento2-mcp-cms-tools', null],
        ]);

        $this->storage->expects($this->once())
            ->method('save')
            ->with([
                ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
            ]);

        $this->cron->execute();
    }

    public function testDetectsUpdateAcrossVprefixMismatch(): void
    {
        // Composer reports a bare installed version; Packagist returns a v-prefixed one.
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->packages->method('getInstalledPackages')->willReturn([
            'magebitcom/magento2-mcp-module' => '0.0.1',
        ]);
        $this->packagist->method('getLatestStableVersion')->willReturn('v0.0.3');

        $this->storage->expects($this->once())
            ->method('save')
            ->with([
                ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '0.0.1', 'latest' => 'v0.0.3'],
            ]);

        $this->cron->execute();
    }

    public function testSavesEmptyWhenEverythingCurrent(): void
    {
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->packages->method('getInstalledPackages')->willReturn([
            'magebitcom/magento2-mcp-module' => '1.2.0',
        ]);
        $this->packagist->method('getLatestStableVersion')->willReturn('1.2.0');

        $this->storage->expects($this->once())->method('save')->with([]);

        $this->cron->execute();
    }

    public function testSwallowsExceptions(): void
    {
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->packages->method('getInstalledPackages')
            ->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->cron->execute();
    }
}
