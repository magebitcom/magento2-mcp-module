<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Marker contract that every MCP tool-module field resolver extends.
 *
 * A "field resolver" is a DI-injected contributor to one tool's response: it
 * owns a named slice of the output (e.g. `"totals"`, `"items"`). Each tool
 * module defines entity-scoped sub-interfaces (`OrderFieldResolverInterface`,
 * `InvoiceFieldResolverInterface`, `CmsBlockFieldResolverInterface`, …) that
 * carry the `resolve(SpecificEntity, array)` signature — this marker exists
 * solely to let {@see \Magebit\Mcp\Model\Util\ResolverPipeline} walk
 * heterogeneous arrays with a single typed parameter.
 *
 * 3rd-party extensions register their resolvers in the relevant tool's
 * `fieldResolvers` DI array and the pipeline pulls them in at call time.
 */
interface FieldResolverInterface
{
    /**
     * Key this resolver contributes to in the response, e.g. `"totals"`.
     *
     * @return string
     */
    public function getKey(): string;

    /**
     * Deterministic order of execution. Lower values render earlier.
     *
     * Defaults to 100 for built-in resolvers so 3rd parties can insert either
     * side without clashing.
     *
     * @return int
     */
    public function getSortOrder(): int;
}
