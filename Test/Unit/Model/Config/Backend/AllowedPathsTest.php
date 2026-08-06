<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config\Backend;

use Magebit\Mcp\Model\Config\Backend\AllowedPaths;
use Magebit\Mcp\Model\Config\ConfigWriteConfig;
use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AllowedPathsTest extends TestCase
{
    /**
     * @return AllowedPaths
     */
    private function field(): AllowedPaths
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createMock(RemoveAction::class));

        return new AllowedPaths(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new ConfigWriteConfig($this->createMock(ScopeConfigInterface::class)),
            new ConfigPathFormat()
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'empty' => [''],
            'single path' => ['tax/calculation/based_on'],
            'several paths' => ["tax/calculation/based_on\ngeneral/store_information/name"],
            'comments and blanks' => ["# a note\n\ntax/calculation/based_on\n"],
            'two segments' => ['carriers/flatrate'],
            // The reader trims each line, so surrounding whitespace still matches at runtime.
            'surrounding whitespace' => ["  tax/calculation/based_on \n"],
        ];
    }

    /**
     * @dataProvider acceptedProvider
     * @param string $value
     * @return void
     */
    public function testWellFormedValuesSave(string $value): void
    {
        $field = $this->field();
        $field->setValue($value);

        $this->assertSame($field, $field->beforeSave());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'single segment' => ['tax', 'tax'],
            // trim() leaves a non-breaking space in place, so this one really never matches.
            'non-breaking space' => ["tax/calculation/based_on\xC2\xA0", 'tax/calculation/based_on'],
            'trailing slash' => ['tax/calculation/', 'tax/calculation/'],
            'doubled slash' => ['tax//calculation/based_on', 'tax//calculation/based_on'],
            'wildcard' => ['tax/calculation/*', 'tax/calculation/*'],
            'hyphen' => ['tax/calc-ulation/based_on', 'tax/calc-ulation/based_on'],
            'one bad line among good ones' => ["tax/calculation/based_on\ntax/*", 'tax/*'],
        ];
    }

    /**
     * @dataProvider rejectedProvider
     * @param string $value
     * @param string $reported
     * @return void
     */
    public function testMalformedPathsAreRefusedAtSaveTime(string $value, string $reported): void
    {
        $field = $this->field();
        $field->setValue($value);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($reported);
        $field->beforeSave();
    }

    /**
     * A non-string value must not slip through untouched — Magento posts arrays for some field types.
     *
     * @return void
     */
    public function testANonStringValueIsRefused(): void
    {
        $field = $this->field();
        $field->setValue(['tax/calculation/based_on']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Allowed Paths');
        $field->beforeSave();
    }
}
