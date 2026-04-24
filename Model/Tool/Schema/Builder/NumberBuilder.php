<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

/**
 * Fluent builder for a `type: number` property — floats (or ints) with
 * optional numeric bounds.
 */
class NumberBuilder extends PropertyBuilder
{
    private float|int|null $minimum = null;

    private float|int|null $maximum = null;

    private float|int|null $exclusiveMinimum = null;

    private float|int|null $exclusiveMaximum = null;

    /**
     * @param float|int $minimum
     * @return $this
     */
    public function minimum(float|int $minimum): self
    {
        $this->minimum = $minimum;
        return $this;
    }

    /**
     * @param float|int $maximum
     * @return $this
     */
    public function maximum(float|int $maximum): self
    {
        $this->maximum = $maximum;
        return $this;
    }

    /**
     * @param float|int $exclusiveMinimum
     * @return $this
     */
    public function exclusiveMinimum(float|int $exclusiveMinimum): self
    {
        $this->exclusiveMinimum = $exclusiveMinimum;
        return $this;
    }

    /**
     * @param float|int $exclusiveMaximum
     * @return $this
     */
    public function exclusiveMaximum(float|int $exclusiveMaximum): self
    {
        $this->exclusiveMaximum = $exclusiveMaximum;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        $schema = ['type' => 'number'];
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
        return $this->withDescription($schema);
    }
}
