<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

/**
 * Fluent JSON-Schema builder for `type: integer` properties.
 */
class IntegerBuilder extends PropertyBuilder
{
    /** @var int|null */
    private ?int $minimum = null;

    /** @var int|null */
    private ?int $maximum = null;

    /** @var int|null */
    private ?int $exclusiveMinimum = null;

    /** @var int|null */
    private ?int $exclusiveMaximum = null;

    /** @var array<int, int>|null */
    private ?array $enum = null;

    /**
     * @param int $minimum
     * @return $this
     */
    public function minimum(int $minimum): self
    {
        $this->minimum = $minimum;
        return $this;
    }

    /**
     * @param int $maximum
     * @return $this
     */
    public function maximum(int $maximum): self
    {
        $this->maximum = $maximum;
        return $this;
    }

    /**
     * @param int $exclusiveMinimum
     * @return $this
     */
    public function exclusiveMinimum(int $exclusiveMinimum): self
    {
        $this->exclusiveMinimum = $exclusiveMinimum;
        return $this;
    }

    /**
     * @param int $exclusiveMaximum
     * @return $this
     */
    public function exclusiveMaximum(int $exclusiveMaximum): self
    {
        $this->exclusiveMaximum = $exclusiveMaximum;
        return $this;
    }

    /**
     * @param array<int, int> $values
     * @return $this
     */
    public function enum(array $values): self
    {
        $this->enum = array_values($values);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        $schema = ['type' => 'integer'];
        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }
        if ($this->exclusiveMinimum !== null) {
            $schema['exclusiveMinimum'] = $this->exclusiveMinimum;
        }
        if ($this->exclusiveMaximum !== null) {
            $schema['exclusiveMaximum'] = $this->exclusiveMaximum;
        }
        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        }
        return $this->withDescription($schema);
    }
}
