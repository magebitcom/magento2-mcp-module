<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Schema;

use InvalidArgumentException;
use LogicException;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    public function testRootSchemaLocksInInvariants(): void
    {
        $schema = Schema::object()->toArray();

        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ], $schema);
    }

    public function testStringPropertyCoversAllKeywords(): void
    {
        $schema = Schema::object()
            ->string('path', fn ($s) => $s
                ->minLength(1)
                ->maxLength(255)
                ->pattern('^[a-z_/]+$')
                ->description('A slash-separated path.')
                ->required()
            )
            ->toArray();

        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'pattern' => '^[a-z_/]+$',
                    'description' => 'A slash-separated path.',
                ],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function testStringEnumRendered(): void
    {
        $schema = Schema::object()
            ->string('scope', fn ($s) => $s->enum(['default', 'websites', 'stores']))
            ->toArray();

        self::assertSame(
            ['type' => 'string', 'enum' => ['default', 'websites', 'stores']],
            $schema['properties']['scope']
        );
    }

    public function testStringFormatRendered(): void
    {
        $schema = Schema::object()
            ->string('email', fn ($s) => $s->format('email'))
            ->toArray();

        self::assertSame(
            ['type' => 'string', 'format' => 'email'],
            $schema['properties']['email']
        );
    }

    public function testIntegerBoundsRendered(): void
    {
        $schema = Schema::object()
            ->integer('qty', fn ($i) => $i
                ->minimum(1)
                ->maximum(99)
                ->exclusiveMinimum(0)
                ->exclusiveMaximum(100)
                ->description('Qty.')
            )
            ->toArray();

        self::assertSame([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 99,
            'exclusiveMinimum' => 0,
            'exclusiveMaximum' => 100,
            'description' => 'Qty.',
        ], $schema['properties']['qty']);
    }

    public function testNumberBoundsRendered(): void
    {
        $schema = Schema::object()
            ->number('price', fn ($n) => $n->minimum(0.01)->maximum(9999.99))
            ->toArray();

        self::assertSame(
            ['type' => 'number', 'minimum' => 0.01, 'maximum' => 9999.99],
            $schema['properties']['price']
        );
    }

    public function testBooleanProperty(): void
    {
        $schema = Schema::object()
            ->boolean('include_inactive', fn ($b) => $b->description('Include inactive.'))
            ->toArray();

        self::assertSame(
            ['type' => 'boolean', 'description' => 'Include inactive.'],
            $schema['properties']['include_inactive']
        );
    }

    public function testArrayOfIntegersWithItemMinimumAndMinItems(): void
    {
        $schema = Schema::object()
            ->array('website_id', fn ($a) => $a
                ->ofIntegers(fn ($i) => $i->minimum(1))
                ->minItems(1)
                ->description('Website ids.')
            )
            ->toArray();

        self::assertSame([
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 1],
            'minItems' => 1,
            'description' => 'Website ids.',
        ], $schema['properties']['website_id']);
    }

    public function testArrayOfStrings(): void
    {
        $schema = Schema::object()
            ->array('fields', fn ($a) => $a->ofStrings()->description('Field whitelist.'))
            ->toArray();

        self::assertSame([
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Field whitelist.',
        ], $schema['properties']['fields']);
    }

    public function testArrayOfObjectsForcesAdditionalPropertiesFalse(): void
    {
        $schema = Schema::object()
            ->array('items', fn ($a) => $a->ofObjects(fn ($o) => $o
                ->integer('item_id', fn ($i) => $i->minimum(1)->required())
                ->integer('qty', fn ($i) => $i->minimum(1)->required())
            ))
            ->toArray();

        self::assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => ['type' => 'integer', 'minimum' => 1],
                    'qty' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['item_id', 'qty'],
                'additionalProperties' => false,
            ],
        ], $schema['properties']['items']);
    }

    public function testNestedObjectPropertyForcesAdditionalPropertiesFalse(): void
    {
        $schema = Schema::object()
            ->object('comment', fn ($o) => $o
                ->string('text', fn ($s) => $s->minLength(1)->required())
                ->boolean('is_visible_on_front', fn ($b) => $b)
                ->description('Optional comment.')
            )
            ->toArray();

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1],
                'is_visible_on_front' => ['type' => 'boolean'],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
            'description' => 'Optional comment.',
        ], $schema['properties']['comment']);
    }

    public function testPropertyInsertionOrderPreserved(): void
    {
        $schema = Schema::object()
            ->string('c', fn ($s) => $s)
            ->string('a', fn ($s) => $s)
            ->string('b', fn ($s) => $s)
            ->toArray();

        self::assertSame(['c', 'a', 'b'], array_keys($schema['properties']));
    }

    public function testRequiredArrayReflectsFlaggedPropertiesOnly(): void
    {
        $schema = Schema::object()
            ->string('a', fn ($s) => $s->required())
            ->string('b', fn ($s) => $s)
            ->string('c', fn ($s) => $s->required())
            ->toArray();

        self::assertSame(['a', 'c'], $schema['required']);
    }

    public function testRequiredArrayOmittedWhenNoneFlagged(): void
    {
        $schema = Schema::object()
            ->string('a', fn ($s) => $s)
            ->toArray();

        self::assertArrayNotHasKey('required', $schema);
    }

    public function testDuplicatePropertyNameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"sku" is already defined');

        Schema::object()
            ->string('sku', fn ($s) => $s)
            ->string('sku', fn ($s) => $s);
    }

    public function testEmptyPropertyNameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::object()->string('', fn ($s) => $s);
    }

    public function testArrayWithoutItemShapeFailsAtBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ArrayBuilder requires an item shape');

        Schema::object()
            ->array('untyped', fn ($a) => $a->minItems(1))
            ->toArray();
    }

    public function testStringEnumRejectsEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::object()->string('x', fn ($s) => $s->enum([]));
    }

    public function testNoOneOfAnyOfAllOfMethodsExist(): void
    {
        $builder = Schema::object();

        self::assertFalse(method_exists($builder, 'oneOf'));
        self::assertFalse(method_exists($builder, 'anyOf'));
        self::assertFalse(method_exists($builder, 'allOf'));
        self::assertFalse(method_exists($builder, 'ref'));
    }

    public function testRawPropertyInjectsVerbatim(): void
    {
        $schema = Schema::object()
            ->rawProperty('filters', [
                'type' => 'object',
                'description' => 'Open-ended filter bag.',
            ])
            ->toArray();

        self::assertSame([
            'type' => 'object',
            'description' => 'Open-ended filter bag.',
        ], $schema['properties']['filters']);
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testRawPropertyCanMarkRequired(): void
    {
        $schema = Schema::object()
            ->rawProperty('filters', ['type' => 'object'], required: true)
            ->toArray();

        self::assertSame(['filters'], $schema['required']);
    }
}
