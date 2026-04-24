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
use LogicException;

/**
 * Fluent builder for a `type: array` property.
 *
 * Item shape is declared through typed convenience methods — `ofStrings()`,
 * `ofIntegers()`, `ofNumbers()`, `ofBooleans()`, `ofObjects()` — which
 * cover every item pattern found across the 57 existing tools. An item
 * shape MUST be set; calling {@see toSchemaArray()} on an ArrayBuilder
 * without one raises at build time rather than emitting an invalid schema.
 */
class ArrayBuilder extends PropertyBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $itemsSchema = null;

    private ?int $minItems = null;

    private ?int $maxItems = null;

    private ?bool $uniqueItems = null;

    /**
     * Items are strings. Optional closure receives a {@see StringBuilder}
     * to attach item-level constraints (pattern, enum, lengths).
     *
     * @param Closure(StringBuilder):void|null $configure
     * @return $this
     */
    public function ofStrings(?Closure $configure = null): self
    {
        $itemBuilder = new StringBuilder();
        if ($configure !== null) {
            $configure($itemBuilder);
        }
        $this->itemsSchema = $itemBuilder->toSchemaArray();
        return $this;
    }

    /**
     * Items are integers. Optional closure receives an {@see IntegerBuilder}
     * to attach item-level bounds.
     *
     * @param Closure(IntegerBuilder):void|null $configure
     * @return $this
     */
    public function ofIntegers(?Closure $configure = null): self
    {
        $itemBuilder = new IntegerBuilder();
        if ($configure !== null) {
            $configure($itemBuilder);
        }
        $this->itemsSchema = $itemBuilder->toSchemaArray();
        return $this;
    }

    /**
     * Items are numbers (floats).
     *
     * @param Closure(NumberBuilder):void|null $configure
     * @return $this
     */
    public function ofNumbers(?Closure $configure = null): self
    {
        $itemBuilder = new NumberBuilder();
        if ($configure !== null) {
            $configure($itemBuilder);
        }
        $this->itemsSchema = $itemBuilder->toSchemaArray();
        return $this;
    }

    /**
     * Items are booleans.
     *
     * @return $this
     */
    public function ofBooleans(): self
    {
        $this->itemsSchema = (new BooleanBuilder())->toSchemaArray();
        return $this;
    }

    /**
     * Items are objects. Closure receives a nested {@see ObjectBuilder}
     * configured like any other object — `string()`, `integer()`,
     * `required()`, etc. `additionalProperties: false` is forced.
     *
     * @param Closure(ObjectBuilder):void $configure
     * @return $this
     */
    public function ofObjects(Closure $configure): self
    {
        $itemBuilder = ObjectBuilder::nested();
        $configure($itemBuilder);
        $this->itemsSchema = $itemBuilder->toSchemaArray();
        return $this;
    }

    /**
     * @param int $minItems
     * @return $this
     */
    public function minItems(int $minItems): self
    {
        if ($minItems < 0) {
            throw new InvalidArgumentException('Array minItems must be >= 0.');
        }
        $this->minItems = $minItems;
        return $this;
    }

    /**
     * @param int $maxItems
     * @return $this
     */
    public function maxItems(int $maxItems): self
    {
        if ($maxItems < 0) {
            throw new InvalidArgumentException('Array maxItems must be >= 0.');
        }
        $this->maxItems = $maxItems;
        return $this;
    }

    /**
     * @param bool $uniqueItems
     * @return $this
     */
    public function uniqueItems(bool $uniqueItems = true): self
    {
        $this->uniqueItems = $uniqueItems;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        if ($this->itemsSchema === null) {
            throw new LogicException(
                'ArrayBuilder requires an item shape — call one of '
                . 'ofStrings(), ofIntegers(), ofNumbers(), ofBooleans(), ofObjects().'
            );
        }
        $schema = ['type' => 'array', 'items' => $this->itemsSchema];
        if ($this->minItems !== null) {
            $schema['minItems'] = $this->minItems;
        }
        if ($this->maxItems !== null) {
            $schema['maxItems'] = $this->maxItems;
        }
        if ($this->uniqueItems !== null) {
            $schema['uniqueItems'] = $this->uniqueItems;
        }
        return $this->withDescription($schema);
    }
}
