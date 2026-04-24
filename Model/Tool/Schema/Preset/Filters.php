<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Preset;

use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Adds the open-bag `filters` object used by every list tool.
 *
 * The `filters` property deliberately has no declared sub-properties and
 * no `additionalProperties` key — clients pass arbitrary filter clauses
 * whose keys are resolved at runtime by the tool's SearchCriteriaBuilder.
 * The whole point is extensibility, so the schema stays open-ended and
 * the typed DSL is sidestepped via {@see ObjectBuilder::rawProperty()}.
 *
 * The single argument is the human-readable description that documents
 * which filter keys the tool supports.
 */
final class Filters implements SchemaContribution
{
    private function __construct(
        private readonly string $description
    ) {
    }

    /**
     * Build a filters preset. `$description` should enumerate the
     * built-in filter keys so the AI client knows what's valid without
     * needing a separate documentation lookup.
     */
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
