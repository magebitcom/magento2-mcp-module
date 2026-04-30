<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

/**
 * Base class for every typed property builder in the schema DSL. Holds
 * cross-cutting `description` and `required` state. `required()` is ignored
 * when a builder is used as an array item schema.
 */
abstract class PropertyBuilder
{
    /** @var string|null */
    protected ?string $description = null;

    /** @var bool */
    protected bool $required = false;

    /**
     * @param string $description
     * @return static
     */
    public function description(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return static
     */
    public function required(): static
    {
        $this->required = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toSchemaArray(): array;

    /**
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
