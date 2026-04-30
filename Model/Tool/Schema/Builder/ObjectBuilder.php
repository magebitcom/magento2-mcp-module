<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

use Closure;
use InvalidArgumentException;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Fluent builder for a `type: object` schema — root or nested. Root emits
 * draft-07 `$schema`; nested skips it. `additionalProperties: false` is
 * forced on every builder-constructed object; open-bag shapes go through
 * {@see rawProperty()}.
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
     * @return self
     */
    public static function root(): self
    {
        return new self(true);
    }

    /**
     * @return self
     */
    public static function nested(): self
    {
        return new self(false);
    }

    /**
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
     * @param SchemaContribution $contribution
     * @return $this
     */
    public function with(SchemaContribution $contribution): self
    {
        $contribution->applyTo($this);
        return $this;
    }

    /**
     * Escape hatch for open-bag shapes the typed DSL can't express.
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
     * @return array{
     *     '$schema': string,
     *     type: 'object',
     *     properties: array<string, array<string, mixed>>,
     *     required?: list<string>,
     *     additionalProperties: false,
     * }
     */
    public function toArray(): array
    {
        /** @phpstan-ignore-next-line shape narrowed via @return above. */
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
