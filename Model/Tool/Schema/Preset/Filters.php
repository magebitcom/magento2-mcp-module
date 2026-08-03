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
 * Produces a `filters` object. With no `$properties`, it stays an open bag
 * (keys resolved at runtime); given `$properties`, it also advertises typed
 * sub-schemas while keeping additional keys allowed via {@see ObjectBuilder::rawProperty()}.
 */
class Filters implements SchemaContribution
{
    /**
     * @param string $description
     * @param array<string, array<string, mixed>> $properties
     */
    private function __construct(
        private readonly string $description,
        private readonly array $properties
    ) {
    }

    /**
     * @param string $description
     * @param array<string, array<string, mixed>> $properties Typed sub-schemas for
     *        the filter keys the tool's SearchCriteriaBuilder supports.
     * @return self
     */
    public static function describing(string $description, array $properties = []): self
    {
        return new self($description, $properties);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $schema = [
            'type' => 'object',
            'description' => $this->description,
        ];
        if ($this->properties !== []) {
            $schema['properties'] = $this->properties;
            $schema['additionalProperties'] = true; // filter-translator keys stay allowed
        }
        $object->rawProperty('filters', $schema);
    }
}
