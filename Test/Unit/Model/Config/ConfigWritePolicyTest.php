<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\ConfigWriteConfig;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ConfigWritePolicyTest extends TestCase
{
    /**
     * @param list<string> $allowed
     * @param bool $enabled
     * @return ConfigWritePolicy
     */
    private function policy(array $allowed, bool $enabled = true): ConfigWritePolicy
    {
        $config = $this->createMock(ConfigWriteConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getAllowedPaths')->willReturn($allowed);

        return new ConfigWritePolicy($config, [
            'web/unsecure',
            'web/secure',
            'admin',
            'payment',
            'system',
            'dev',
            'oauth',
            'magebit_mcp',
        ]);
    }

    public function testAnAllowlistedPathIsWritable(): void
    {
        $this->expectNotToPerformAssertions();
        $this->policy(['tax/calculation/based_on'])->assertWritable('tax/calculation/based_on');
    }

    public function testAPathOutsideTheAllowlistIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/not in the configured allowlist/');
        $this->policy(['tax/calculation/based_on'])->assertWritable('tax/defaults/country');
    }

    public function testAnEmptyAllowlistRefusesEverything(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/No configuration paths are writable/');
        $this->policy([])->assertWritable('tax/calculation/based_on');
    }

    public function testADisabledToolRefusesEverything(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Configuration writing is disabled/');
        $this->policy(['tax/calculation/based_on'], false)->assertWritable('tax/calculation/based_on');
    }

    public function testProtectedPathIsRefusedEvenWhenAllowlisted(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/is protected by "payment"/');
        $this->policy(['payment/checkmo/active'])->assertWritable('payment/checkmo/active');
    }

    public function testTheModuleCannotWidenItsOwnGatesEvenWhenAllowlisted(): void
    {
        $policy = $this->policy([
            'magebit_mcp/general/allow_writes',
            'magebit_mcp/config_write/allowed_paths',
            'magebit_mcp/db_tools/allowed_tables',
        ]);

        foreach ([
            'magebit_mcp/general/allow_writes',
            'magebit_mcp/config_write/allowed_paths',
            'magebit_mcp/db_tools/allowed_tables',
        ] as $path) {
            try {
                $policy->assertWritable($path);
                $this->fail(sprintf('Expected "%s" to be refused as protected.', $path));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('is protected by "magebit_mcp"', $e->getMessage());
            }
        }
    }

    public function testPrefixMatchingDoesNotOverreachIntoUnrelatedSections(): void
    {
        $this->expectNotToPerformAssertions();
        // "developer/..." must not be caught by the "dev" protected prefix.
        $this->policy(['developer/mode/flag'])->assertWritable('developer/mode/flag');
    }

    public function testProtectedPrefixIsReportedForDiagnostics(): void
    {
        $this->assertSame('payment', $this->policy([])->findProtectedPrefix('payment/checkmo/active'));
        $this->assertNull($this->policy([])->findProtectedPrefix('tax/calculation/based_on'));
    }
}
