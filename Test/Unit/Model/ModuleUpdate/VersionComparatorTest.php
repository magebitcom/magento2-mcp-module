<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\ModuleUpdate;

use Composer\Semver\VersionParser;
use Magebit\Mcp\Model\ModuleUpdate\VersionComparator;
use PHPUnit\Framework\TestCase;

class VersionComparatorTest extends TestCase
{
    /**
     * @var VersionComparator
     */
    private VersionComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new VersionComparator(new VersionParser());
    }

    /**
     * @dataProvider versionProvider
     * @param string $candidate
     * @param string $current
     * @param bool $expected
     * @return void
     */
    public function testIsNewer(string $candidate, string $current, bool $expected): void
    {
        $this->assertSame($expected, $this->comparator->isNewer($candidate, $current));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public function versionProvider(): array
    {
        return [
            'bare newer'                 => ['0.0.3', '0.0.1', true],
            'bare older'                 => ['0.0.1', '0.0.3', false],
            'equal'                      => ['1.2.0', '1.2.0', false],
            // The case raw composer Comparator gets wrong:
            'v-prefixed vs bare newer'   => ['v0.0.3', '0.0.1', true],
            'bare vs v-prefixed newer'   => ['0.0.4', 'v0.0.3', true],
            'both v-prefixed newer'      => ['v1.0.0', 'v0.0.3', true],
            'v-prefixed equal'           => ['v0.0.3', 'v0.0.3', false],
            'minor bump'                 => ['1.3.0', '1.2.9', true],
        ];
    }

    public function testInvalidVersionIsNotNewer(): void
    {
        $this->assertFalse($this->comparator->isNewer('not-a-version', '1.0.0'));
        $this->assertFalse($this->comparator->isNewer('1.0.0', 'garbage'));
    }
}
