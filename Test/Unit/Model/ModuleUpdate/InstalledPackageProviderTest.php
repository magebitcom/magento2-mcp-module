<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\ModuleUpdate;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\ModuleUpdate\ComposerVersionResolver;
use Magebit\Mcp\Model\ModuleUpdate\InstalledPackageProvider;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InstalledPackageProviderTest extends TestCase
{
    /**
     * @var FullModuleList
     * @phpstan-var FullModuleList&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private FullModuleList&MockObject $moduleList;

    /**
     * @var ComponentRegistrarInterface
     * @phpstan-var ComponentRegistrarInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ComponentRegistrarInterface&MockObject $componentRegistrar;

    /**
     * @var File
     * @phpstan-var File&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private File&MockObject $fileDriver;

    /**
     * @var ComposerVersionResolver
     * @phpstan-var ComposerVersionResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ComposerVersionResolver&MockObject $versionResolver;

    /**
     * @var LoggerInterface
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    /**
     * @var InstalledPackageProvider
     */
    private InstalledPackageProvider $provider;

    protected function setUp(): void
    {
        $this->moduleList = $this->createMock(FullModuleList::class);
        $this->componentRegistrar = $this->createMock(ComponentRegistrarInterface::class);
        $this->fileDriver = $this->createMock(File::class);
        $this->versionResolver = $this->createMock(ComposerVersionResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new InstalledPackageProvider(
            $this->moduleList,
            $this->componentRegistrar,
            $this->fileDriver,
            $this->versionResolver,
            $this->logger
        );
    }

    public function testReturnsResolvableMcpPackagesOnly(): void
    {
        $this->moduleList->method('getNames')->willReturn([
            'Magento_Catalog',          // non-Magebit, skipped
            'Magebit_Mcp',              // resolvable
            'Magebit_McpOrderTools',    // version unknown, skipped
        ]);

        $this->componentRegistrar->method('getPath')->willReturnMap([
            [ComponentRegistrar::MODULE, 'Magebit_Mcp', '/app/Magebit/Mcp'],
            [ComponentRegistrar::MODULE, 'Magebit_McpOrderTools', '/app/Magebit/McpOrderTools'],
        ]);

        $this->fileDriver->method('isExists')->willReturn(true);
        $this->fileDriver->method('fileGetContents')->willReturnMap([
            ['/app/Magebit/Mcp/composer.json', null, null, '{"name":"magebitcom/magento2-mcp-module"}'],
            ['/app/Magebit/McpOrderTools/composer.json', null, null, '{"name":"magebitcom/magento2-mcp-order-tools"}'],
        ]);

        $this->versionResolver->method('getInstalledVersion')->willReturnMap([
            ['magebitcom/magento2-mcp-module', '1.0.0'],
            ['magebitcom/magento2-mcp-order-tools', null],
        ]);

        $this->assertSame(
            ['magebitcom/magento2-mcp-module' => '1.0.0'],
            $this->provider->getInstalledPackages()
        );
    }

    public function testSkipsModuleWithMissingComposerJson(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Magebit_Mcp']);
        $this->componentRegistrar->method('getPath')->willReturn('/app/Magebit/Mcp');
        $this->fileDriver->method('isExists')->willReturn(false);
        $this->versionResolver->expects($this->never())->method('getInstalledVersion');

        $this->assertSame([], $this->provider->getInstalledPackages());
    }

    public function testSkipsModuleWithMalformedComposerJson(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Magebit_Mcp']);
        $this->componentRegistrar->method('getPath')->willReturn('/app/Magebit/Mcp');
        $this->fileDriver->method('isExists')->willReturn(true);
        $this->fileDriver->method('fileGetContents')->willReturn('{not valid json');

        $this->assertSame([], $this->provider->getInstalledPackages());
    }

    public function testNullRegistrarPathIsSkipped(): void
    {
        $this->moduleList->method('getNames')->willReturn(['Magebit_Mcp']);
        $this->componentRegistrar->method('getPath')->willReturn(null);
        $this->fileDriver->expects($this->never())->method('isExists');

        $this->assertSame([], $this->provider->getInstalledPackages());
    }
}
