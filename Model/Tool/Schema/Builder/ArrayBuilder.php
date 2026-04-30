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
use LogicException;

/**
 * Fluent JSON-Schema builder for `type: array` properties. The item shape is required —
 * `toSchemaArray()` raises if none of the `of*()` methods has been called.
 */
class ArrayBuilder extends PropertyBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $itemsSchema = null;

    /** @var int|null */
    private ?int $minItems = null;

    /** @var int|null */
    private ?int $maxItems = null;

    /** @var bool|null */
    private ?bool $uniqueItems = null;

    /**
     * @param Closure(StringBuilder):mixed|null $configure
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
     * @param Closure(IntegerBuilder):mixed|null $configure
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
     * @param Closure(NumberBuilder):mixed|null $configure
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
     * @return $this
     */
    public function ofBooleans(): self
    {
        $this->itemsSchema = (new BooleanBuilder())->toSchemaArray();
        return $this;
    }

    /**
     * `additionalProperties: false` is forced on the nested object.
     *
     * @param Closure(ObjectBuilder):mixed $configure
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
