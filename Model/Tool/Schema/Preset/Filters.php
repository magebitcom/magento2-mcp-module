<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Produces an open-bag `filters` object. Sub-properties are undeclared
 * because keys are resolved at runtime by the tool's SearchCriteriaBuilder;
 * emitted via {@see ObjectBuilder::rawProperty()}.
 */
final class Filters implements SchemaContribution
{
    private function __construct(
        private readonly string $description
    ) {
    }

    public static function describing(string $description): self
    {
        return new self($description);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $object->rawProperty('filters', [
            'type' => 'object',
            'description' => $this->description,
        ]);
    }
}
