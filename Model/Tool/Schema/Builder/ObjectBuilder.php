<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

use Closure;
use InvalidArgumentException;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Fluent builder for a `type: object` schema — root or nested.
 *
 * The root variant is what {@see \Magebit\Mcp\Model\Tool\Schema\Schema::object()}
 * returns: `toArray()` emits `$schema` + `type` + `properties` + `required`
 * + `additionalProperties: false`. Nested variants skip `$schema`.
 *
 * `additionalProperties: false` is forced on every builder-constructed
 * object. Open-bag object shapes (e.g. the `filters` field on list tools,
 * whose keys are enumerated at runtime) are emitted by presets via
 * {@see rawProperty()} rather than by the typed DSL.
 */
class ObjectBuilder extends PropertyBuilder
{
    private const SCHEMA_URI = 'http://json-schema.org/draft-07/schema#';

    /** @var array<string, array<string, mixed>> */
    private array $properties = [];

    /** @var array<int, string> */
    private array $requiredKeys = [];

    /**
     * @param bool $isRoot
     */
    private function __construct(
        private readonly bool $isRoot
    ) {
    }

    /**
     * Create a root-level object schema — the one a tool returns from
     * {@see \Magebit\Mcp\Api\ToolInterface::getInputSchema()}.
     */
    public static function root(): self
    {
        return new self(true);
    }

    /**
     * Create a nested object schema — used for `->object()` properties
     * and for `ArrayBuilder::ofObjects()` item shapes.
     */
    public static function nested(): self
    {
        return new self(false);
    }

    /**
     * Add a string property. The closure receives a {@see StringBuilder}
     * to configure constraints.
     *
     * @param string $name
     * @param Closure(StringBuilder):mixed $configure
     * @return $this
     */
    public function string(string $name, Closure $configure): self
    {
        $builder = new StringBuilder();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Add an integer property.
     *
     * @param string $name
     * @param Closure(IntegerBuilder):mixed $configure
     * @return $this
     */
    public function integer(string $name, Closure $configure): self
    {
        $builder = new IntegerBuilder();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Add a number property.
     *
     * @param string $name
     * @param Closure(NumberBuilder):mixed $configure
     * @return $this
     */
    public function number(string $name, Closure $configure): self
    {
        $builder = new NumberBuilder();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Add a boolean property.
     *
     * @param string $name
     * @param Closure(BooleanBuilder):mixed $configure
     * @return $this
     */
    public function boolean(string $name, Closure $configure): self
    {
        $builder = new BooleanBuilder();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Add an array property.
     *
     * @param string $name
     * @param Closure(ArrayBuilder):mixed $configure
     * @return $this
     */
    public function array(string $name, Closure $configure): self
    {
        $builder = new ArrayBuilder();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Add a nested object property. `additionalProperties: false` is forced
     * on the nested object.
     *
     * @param string $name
     * @param Closure(ObjectBuilder):mixed $configure
     * @return $this
     */
    public function object(string $name, Closure $configure): self
    {
        $builder = self::nested();
        $configure($builder);
        $this->addProperty($name, $builder);
        return $this;
    }

    /**
     * Apply a {@see SchemaContribution} — typically a preset from
     * `Model/Tool/Schema/Preset/` — which adds its own named properties.
     *
     * @param SchemaContribution $contribution
     * @return $this
     */
    public function with(SchemaContribution $contribution): self
    {
        $contribution->applyTo($this);
        return $this;
    }

    /**
     * Escape hatch: inject a fully-formed JSON-Schema fragment as a named
     * property. Used by presets that emit open-bag objects (e.g. `filters`)
     * which the typed DSL intentionally can't express. Application code
     * should reach for the typed methods first.
     *
     * @param string $name
     * @param array<string, mixed> $schema
     * @param bool $required
     * @return $this
     */
    public function rawProperty(string $name, array $schema, bool $required = false): self
    {
        $this->assertPropertyNameUnique($name);
        $this->properties[$name] = $schema;
        if ($required) {
            $this->requiredKeys[] = $name;
        }
        return $this;
    }

    /**
     * Root-variant convenience — terminal call on a root builder.
     *
     * @return array<string, mixed>
     * @phpstan-return array{
     *     '$schema': string,
     *     type: 'object',
     *     properties: array<string, array<string, mixed>>,
     *     required?: list<string>,
     *     additionalProperties: false,
     * }
     */
    public function toArray(): array
    {
        /** @phpstan-ignore-next-line narrowed for callers via @phpstan-return above. */
        return $this->toSchemaArray();
    }

    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        $schema = [];
        if ($this->isRoot) {
            $schema['$schema'] = self::SCHEMA_URI;
        }
        $schema['type'] = 'object';
        $schema['properties'] = $this->properties;
        if ($this->requiredKeys !== []) {
            $schema['required'] = array_values(array_unique($this->requiredKeys));
        }
        $schema['additionalProperties'] = false;
        if (!$this->isRoot) {
            $schema = $this->withDescription($schema);
        }
        return $schema;
    }

    /**
     * Attach a configured property builder as a named property, recording
     * its `required` state on the enclosing object.
     *
     * @param string $name
     * @param PropertyBuilder $builder
     * @return void
     */
    private function addProperty(string $name, PropertyBuilder $builder): void
    {
        $this->assertPropertyNameUnique($name);
        $this->properties[$name] = $builder->toSchemaArray();
        if ($builder->isRequired()) {
            $this->requiredKeys[] = $name;
        }
    }

    /**
     * Fail at build time if the same property is added twice — this
     * catches the class of typo where a tool author copy-pastes a block
     * and forgets to rename one of the keys.
     *
     * @param string $name
     * @return void
     */
    private function assertPropertyNameUnique(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Property name must be non-empty.');
        }
        if (array_key_exists($name, $this->properties)) {
            throw new InvalidArgumentException(sprintf(
                'Property "%s" is already defined on this object.',
                $name
            ));
        }
    }
}
