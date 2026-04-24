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
 * Contract for a reusable block of schema that contributes properties to
 * an {@see ObjectBuilder}. Presets for the recurring list-tool patterns
 * (pagination, sort, field selection, filters) implement this interface
 * and are applied via {@see ObjectBuilder::with()}.
 */
interface SchemaContribution
{
    /**
     * Mutate the given object builder to add this contribution's properties.
     *
     * Implementations MUST NOT replace properties that already exist on the
     * builder — they should only add their own named slice.
     *
     * @param ObjectBuilder $object
     * @return void
     */
    public function applyTo(ObjectBuilder $object): void;
}
