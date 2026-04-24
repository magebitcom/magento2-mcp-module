<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;

/**
 * Entry point for the fluent input-schema DSL. Locks in MCP-compliant
 * invariants: draft-07 `$schema`, root `type: object`, `additionalProperties:
 * false` everywhere, and no forbidden composition keywords.
 *
 * ```php
 * return Schema::object()
 *     ->string('sku', fn ($s) => $s
 *         ->minLength(1)
 *         ->maxLength(64)
 *         ->description('Product SKU.')
 *         ->required()
 *     )
 *     ->integer('qty', fn ($i) => $i->minimum(1)->required())
 *     ->toArray();
 * ```
 */
final class Schema
{
    public static function object(): ObjectBuilder
    {
        return ObjectBuilder::root();
    }

    private function __construct()
    {
    }
}
