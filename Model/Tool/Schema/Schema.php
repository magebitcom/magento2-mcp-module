<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;

/**
 * Entry point for the fluent input-schema DSL used by MCP tools in place
 * of raw JSON-Schema arrays.
 *
 * Typical usage inside {@see \Magebit\Mcp\Api\ToolInterface::getInputSchema()}:
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
 *
 * The builder locks in MCP-compliant invariants — draft-07 `$schema`,
 * `type: object` at the root, `additionalProperties: false` everywhere —
 * and refuses (at the API surface) the composition keywords the MCP
 * spec forbids: `oneOf` / `anyOf` / `allOf` / `if` / `then` / `else` /
 * `not` / `$ref` / `$defs`. If a rare tool genuinely needs them, fall
 * back to a hand-written array and accept the {@see \Magebit\Mcp\Model\Tool\SchemaSanitizer}
 * runtime warning.
 */
final class Schema
{
    /**
     * Start a new root-level object schema.
     */
    public static function object(): ObjectBuilder
    {
        return ObjectBuilder::root();
    }

    private function __construct()
    {
    }
}
