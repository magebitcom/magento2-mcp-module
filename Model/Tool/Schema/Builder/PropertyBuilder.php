<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

/**
 * Base class shared by every typed property builder in the schema DSL.
 *
 * Holds the two cross-cutting fields every builder exposes: the free-text
 * `description` that reaches the AI client, and the `required` flag that
 * the enclosing {@see ObjectBuilder} reads to build its own `required` list.
 *
 * `required()` is meaningful only when the builder is added as a property
 * on an {@see ObjectBuilder}. When used as an array item schema the flag
 * is silently ignored.
 */
abstract class PropertyBuilder
{
    protected ?string $description = null;

    protected bool $required = false;

    /**
     * Free-text guidance surfaced to the AI client via the JSON Schema
     * `description` keyword. Keep it action-oriented — this is the field
     * the model reads when deciding how to fill the argument.
     *
     * @param string $description
     * @return static
     */
    public function description(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Mark this property as required on its enclosing object.
     *
     * @return static
     */
    public function required(): static
    {
        $this->required = true;
        return $this;
    }

    /**
     * Whether this property was marked required. Read by the parent
     * {@see ObjectBuilder} when assembling its `required` array.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Emit the JSON-Schema fragment for this property.
     *
     * Implementations MUST include `type` and any type-specific constraints.
     * They MUST include `description` when set. They MUST NOT emit the
     * `required` flag — that's the enclosing object's concern.
     *
     * @return array<string, mixed>
     */
    abstract public function toSchemaArray(): array;

    /**
     * Merge the cross-cutting `description` keyword into a type-specific
     * schema fragment. Called by concrete builders at the end of their own
     * {@see toSchemaArray()} implementations so every property has a
     * uniform ordering: type first, constraints next, description last.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function withDescription(array $schema): array
    {
        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }
        return $schema;
    }
}
