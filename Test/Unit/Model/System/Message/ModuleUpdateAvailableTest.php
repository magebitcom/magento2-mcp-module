<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\System\Message;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\ModuleUpdate\UpdateSummaryStorage;
use Magebit\Mcp\Model\System\Message\ModuleUpdateAvailable;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ModuleUpdateAvailableTest extends TestCase
{
    /**
     * @var AuthorizationInterface
     * @phpstan-var AuthorizationInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AuthorizationInterface&MockObject $authorization;

    /**
     * @var UpdateSummaryStorage
     * @phpstan-var UpdateSummaryStorage&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private UpdateSummaryStorage&MockObject $storage;

    /**
     * @var ModuleConfig
     * @phpstan-var ModuleConfig&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ModuleConfig&MockObject $config;

    /**
     * @phpstan-param list<array{package: string, installed: string, latest: string}> $summary
     * @param array $summary
     * @return ModuleUpdateAvailable
     */
    private function build(array $summary): ModuleUpdateAvailable
    {
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->storage = $this->createMock(UpdateSummaryStorage::class);
        $this->config = $this->createMock(ModuleConfig::class);

        $this->storage->method('load')->willReturn($summary);

        return new ModuleUpdateAvailable($this->authorization, $this->storage, $this->config);
    }

    public function testDisplayedWhenEnabledAllowedAndOutdated(): void
    {
        $message = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->authorization->method('isAllowed')->with('Magebit_Mcp::config')->willReturn(true);

        $this->assertTrue($message->isDisplayed());
    }

    public function testNotDisplayedWhenDisabled(): void
    {
        $message = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(false);

        $this->assertFalse($message->isDisplayed());
    }

    public function testNotDisplayedWhenNotAllowed(): void
    {
        $message = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->authorization->method('isAllowed')->willReturn(false);

        $this->assertFalse($message->isDisplayed());
    }

    public function testNotDisplayedWhenSummaryEmpty(): void
    {
        $message = $this->build([]);
        $this->config->method('isModuleUpdateCheckEnabled')->willReturn(true);
        $this->authorization->method('isAllowed')->willReturn(true);

        $this->assertFalse($message->isDisplayed());
    }

    public function testSeverityIsMinor(): void
    {
        $message = $this->build([]);

        $this->assertSame(MessageInterface::SEVERITY_MINOR, $message->getSeverity());
    }

    public function testIdentityIsStableRegardlessOfOrder(): void
    {
        $a = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
            ['package' => 'magebitcom/magento2-mcp-cms-tools', 'installed' => '1.0.0', 'latest' => '1.1.0'],
        ]);
        $b = $this->build([
            ['package' => 'magebitcom/magento2-mcp-cms-tools', 'installed' => '1.0.0', 'latest' => '1.1.0'],
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);

        $this->assertSame($a->getIdentity(), $b->getIdentity());
    }

    public function testIdentityChangesWhenLatestVersionChanges(): void
    {
        $a = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);
        $b = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.3.0'],
        ]);

        $this->assertNotSame($a->getIdentity(), $b->getIdentity());
    }

    public function testTextListsOutdatedPackages(): void
    {
        $message = $this->build([
            ['package' => 'magebitcom/magento2-mcp-module', 'installed' => '1.0.0', 'latest' => '1.2.0'],
        ]);

        $text = (string) $message->getText();
        $this->assertStringContainsString('magebitcom/magento2-mcp-module', $text);
        $this->assertStringContainsString('1.0.0', $text);
        $this->assertStringContainsString('1.2.0', $text);
    }
}
