<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use PHPUnit\Framework\TestCase;

/**
 * Guards the di.xml wiring, because every other test here mocks Structure and so cannot notice that
 * the injected one is the area-scoped default, which is empty on the frontend route MCP serves.
 */
class ConfigStructureWiringTest extends TestCase
{
    private const STRUCTURE = 'Magebit\Mcp\Model\Config\Structure\Adminhtml';

    private ?\SimpleXMLElement $di = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__ . '/../../../../etc/di.xml';
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'etc/di.xml did not parse.');
        $this->di = $xml;
    }

    /**
     * @param string $name
     * @return \SimpleXMLElement
     */
    private function node(string $name): \SimpleXMLElement
    {
        $found = $this->di?->xpath(sprintf('//virtualType[@name="%s"] | //type[@name="%s"]', $name, $name)) ?: [];
        $this->assertCount(1, $found, sprintf('Expected exactly one di.xml node named "%s".', $name));

        return $found[0];
    }

    /**
     * @param \SimpleXMLElement $node
     * @param string $argument
     * @return string
     */
    private function argument(\SimpleXMLElement $node, string $argument): string
    {
        $found = $node->xpath(sprintf('arguments/argument[@name="%s"]', $argument)) ?: [];
        $this->assertCount(1, $found, sprintf('Expected one "%s" argument.', $argument));

        return trim((string) $found[0]);
    }

    public function testTheScopeVirtualTypeForcesAdminhtml(): void
    {
        $node = $this->node(self::STRUCTURE . 'Scope');

        $this->assertSame('Magento\Framework\Config\Scope', (string) $node['type']);
        $this->assertSame('adminhtml', $this->argument($node, 'defaultScope'));
    }

    public function testTheStructureDataVirtualTypeUsesThatScope(): void
    {
        $node = $this->node(self::STRUCTURE . 'Data');

        $this->assertSame('Magento\Config\Model\Config\Structure\Data', (string) $node['type']);
        $this->assertSame(self::STRUCTURE . 'Scope', $this->argument($node, 'configScope'));
    }

    public function testTheStructureVirtualTypeUsesThatData(): void
    {
        $node = $this->node(self::STRUCTURE);

        $this->assertSame('Magento\Config\Model\Config\Structure', (string) $node['type']);
        $this->assertSame(self::STRUCTURE . 'Data', $this->argument($node, 'structureData'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function structureConsumerProvider(): array
    {
        return [
            'sensitive field guard' => ['Magebit\Mcp\Model\Config\SensitiveFieldGuard'],
            'config path writer' => ['Magebit\Mcp\Model\Config\ConfigPathWriter'],
        ];
    }

    public function testTheLazyVirtualTypeWrapsTheAdminhtmlStructure(): void
    {
        $node = $this->node(self::STRUCTURE . 'Lazy');

        $this->assertSame('Magento\Config\Model\Config\Structure\Proxy', (string) $node['type']);
        $this->assertSame(self::STRUCTURE, $this->argument($node, 'instanceName'));
    }

    /**
     * The compiler resolves every di.xml name ending in "Proxy" as a generated proxy class, so a
     * virtual type named that way fails setup:di:compile outright.
     *
     * @return void
     */
    public function testNoVirtualTypeIsNamedWithAProxySuffix(): void
    {
        $names = [];
        foreach ($this->di?->xpath('//virtualType') ?: [] as $virtualType) {
            $names[] = (string) $virtualType['name'];
        }

        $this->assertNotEmpty($names);
        $this->assertSame([], array_values(array_filter(
            $names,
            static fn (string $name): bool => str_ends_with($name, 'Proxy')
        )));
    }

    /**
     * @dataProvider structureConsumerProvider
     * @param string $class
     * @return void
     */
    public function testEveryStructureConsumerGetsTheAdminhtmlStructure(string $class): void
    {
        $this->assertSame(
            self::STRUCTURE . 'Lazy',
            $this->argument($this->node($class), 'configStructure')
        );
    }

    /**
     * Every constructor in the module asking for a Structure must be wired, or it silently receives
     * the empty frontend one — the bug this wiring exists to prevent.
     *
     * @return void
     */
    public function testNoStructureConsumerIsLeftUnwired(): void
    {
        $wired = array_map(static fn (array $row): string => $row[0], self::structureConsumerProvider());

        $found = [];
        $base = dirname(__DIR__, 4);
        /** @var iterable<string, \SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/Test/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (!preg_match('/^\s*(?:private|protected|public)?\s*(?:readonly\s+)?Structure\s+\$/m', $source)
                || !str_contains($source, 'use Magento\Config\Model\Config\Structure;')
            ) {
                continue;
            }
            $found[] = 'Magebit\Mcp\\' . str_replace(
                '/',
                '\\',
                substr($path, strlen($base) + 1, -4)
            );
        }

        sort($found);
        sort($wired);
        $this->assertSame($wired, $found, 'A class takes a Structure but has no di.xml wiring.');
    }
}
