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
 * Reusable schema block applied via {@see ObjectBuilder::with()}.
 */
interface SchemaContribution
{
    /**
     * Implementations MUST NOT replace properties that already exist.
     */
    public function applyTo(ObjectBuilder $object): void;
}
