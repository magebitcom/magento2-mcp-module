<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Util;

use Magebit\Mcp\Model\Util\ConfigPathFormat;
use PHPUnit\Framework\TestCase;

class ConfigPathFormatTest extends TestCase
{
    private ?ConfigPathFormat $format = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->format = new ConfigPathFormat();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pathProvider(): array
    {
        return [
            'two segments' => ['tax/calculation', true],
            'three segments' => ['tax/calculation/based_on', true],
            'four segments' => ['section/outer/inner/flag', true],
            'digits and underscores' => ['a_1/b_2/c_3', true],
            'uppercase' => ['Tax/Calculation/BasedOn', true],
            'single segment' => ['tax', false],
            'empty' => ['', false],
            'leading slash' => ['/tax/calculation/based_on', false],
            'trailing slash' => ['tax/calculation/based_on/', false],
            'doubled slash' => ['tax//based_on', false],
            'leading space' => [' tax/calculation/based_on', false],
            'trailing space' => ['tax/calculation/based_on ', false],
            'embedded space' => ['tax/calc ulation/based_on', false],
            'blank segment' => ['tax/ /based_on', false],
            'trailing newline' => ["tax/calculation/based_on\n", false],
            'non-breaking space' => ["tax/calc\u{00A0}ulation/based_on", false],
            'hyphen' => ['tax/calc-ulation/based_on', false],
            'dot' => ['tax.calculation.based_on', false],
            'null byte' => ["tax/calculation/based_on\x00", false],
        ];
    }

    /**
     * @dataProvider pathProvider
     * @param string $path
     * @param bool $expected
     * @return void
     */
    public function testIsCanonical(string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->format?->isCanonical($path));
    }

    public function testForMessageLeavesACleanValueAlone(): void
    {
        $this->assertSame('tax/calculation/based_on', $this->format?->forMessage('tax/calculation/based_on'));
    }

    public function testForMessageCollapsesControlCharacters(): void
    {
        $this->assertSame('tax/calc ulation', $this->format?->forMessage("tax/calc\x00\x01ulation"));
    }

    public function testForMessageTruncatesBeyondTheCap(): void
    {
        $result = (string) $this->format?->forMessage(str_repeat('a', 5000));

        $this->assertSame(str_repeat('a', 120) . '...', $result);
    }

    public function testForMessageKeepsAValueAtTheCapIntact(): void
    {
        $exact = str_repeat('a', 120);

        $this->assertSame($exact, $this->format?->forMessage($exact));
    }

    /**
     * Invalid UTF-8 reaching the JSON-RPC error string would break json_encode().
     *
     * @return void
     */
    public function testForMessageReturnsEncodableUtf8ForInvalidInput(): void
    {
        $result = (string) $this->format?->forMessage("tax/\xC3\x28/based_on");

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertNotFalse(json_encode($result, JSON_THROW_ON_ERROR));
    }
}
