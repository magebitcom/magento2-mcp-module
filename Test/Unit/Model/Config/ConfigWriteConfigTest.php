<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\ConfigWriteConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigWriteConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;

    private ConfigWriteConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new ConfigWriteConfig($this->scopeConfig);
    }

    public function testIsEnabledReadsItsOwnFlagPath(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(ConfigWriteConfig::XML_PATH_ENABLED)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledIsFalseWhenTheFlagIsOff(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->config->isEnabled());
    }

    /**
     * @param mixed $raw
     * @param list<string> $expected
     * @return void
     * @dataProvider allowedPathsProvider
     */
    public function testGetAllowedPathsParsesTheTextarea(mixed $raw, array $expected): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(ConfigWriteConfig::XML_PATH_ALLOWED_PATHS)
            ->willReturn($raw);

        $this->assertSame($expected, $this->config->getAllowedPaths());
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function allowedPathsProvider(): array
    {
        return [
            'null value' => [null, []],
            'empty string' => ['', []],
            'whitespace only' => ["  \n\t \n", []],
            'non-string value' => [42, []],
            'comments only' => ["# nothing here\n#   still nothing", []],
            'single path' => ['tax/calculation/based_on', ['tax/calculation/based_on']],
            'bare carriage returns delimit lines' => [
                "a/b/c\rd/e/f",
                ['a/b/c', 'd/e/f'],
            ],
            'crlf and comments' => [
                "# header\r\ntax/calculation/based_on\r\n\r\n  design/head/demonotice  \r\n# trailing",
                ['tax/calculation/based_on', 'design/head/demonotice'],
            ],
            'duplicates collapse and keys stay sequential' => [
                "a/b/c\na/b/c\nd/e/f",
                ['a/b/c', 'd/e/f'],
            ],
        ];
    }
}
