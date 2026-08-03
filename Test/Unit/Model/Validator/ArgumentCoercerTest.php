<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Validator;

use Magebit\Mcp\Model\Validator\ArgumentCoercer;
use PHPUnit\Framework\TestCase;

class ArgumentCoercerTest extends TestCase
{
    private ArgumentCoercer $coercer;

    protected function setUp(): void
    {
        $this->coercer = new ArgumentCoercer();
    }

    public function testCoercesStringifiedScalarsPerSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'page_size' => ['type' => 'integer'],
            'price' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
            'name' => ['type' => 'string'],
        ]];
        $out = $this->coercer->coerce($schema, [
            'page_size' => '50', 'price' => '9.95', 'active' => 'true', 'name' => '7',
        ]);
        self::assertSame(['page_size' => 50, 'price' => 9.95, 'active' => true, 'name' => '7'], $out);
    }

    public function testRecursesIntoDeclaredObjectAndArrayProperties(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'filters' => ['type' => 'object', 'properties' => [
                'store_id' => ['type' => ['integer', 'array']],
            ]],
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]];
        $out = $this->coercer->coerce($schema, [
            'filters' => ['store_id' => '1'],
            'ids' => ['5', '6'],
        ]);
        self::assertSame(['filters' => ['store_id' => 1], 'ids' => [5, 6]], $out);
    }

    public function testLeavesUncoercibleAndUndeclaredValuesUntouched(): void
    {
        $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
        $out = $this->coercer->coerce($schema, ['n' => 'abc', 'extra' => '1']);
        self::assertSame(['n' => 'abc', 'extra' => '1'], $out);
    }

    public function testOversizedIntegerStringIsLeftUntouched(): void
    {
        $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
        $out = $this->coercer->coerce($schema, ['n' => '99999999999999999999999999']);
        self::assertSame(['n' => '99999999999999999999999999'], $out);
    }
}
