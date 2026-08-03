<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Preset\Filters;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use PHPUnit\Framework\TestCase;

class FiltersTest extends TestCase
{
    public function testTypedPropertiesAreEmittedWithOpenAdditionalProperties(): void
    {
        $schema = Schema::object()
            ->with(Filters::describing('desc', [
                'store_id' => ['type' => ['integer', 'array']],
            ]))
            ->toArray();
        self::assertSame(
            [
                'type' => 'object',
                'description' => 'desc',
                'properties' => ['store_id' => ['type' => ['integer', 'array']]],
                'additionalProperties' => true,
            ],
            $schema['properties']['filters']
        );
    }

    public function testLegacySingleArgumentFormStaysOpenBag(): void
    {
        $schema = Schema::object()->with(Filters::describing('desc'))->toArray();
        self::assertSame(['type' => 'object', 'description' => 'desc'], $schema['properties']['filters']);
    }
}
