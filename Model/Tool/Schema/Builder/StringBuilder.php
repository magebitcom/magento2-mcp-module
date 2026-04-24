<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Schema\Builder;

use InvalidArgumentException;

/**
 * Fluent builder for a `type: string` property.
 *
 * Exposes the subset of JSON-Schema string keywords the MCP tools surveyed
 * across the Magebit ecosystem actually use: `minLength`, `maxLength`,
 * `pattern`, `enum`, `format`. Anything else falls back to a raw array.
 */
class StringBuilder extends PropertyBuilder
{
    private ?int $minLength = null;

    private ?int $maxLength = null;

    private ?string $pattern = null;

    /** @var array<int, string>|null */
    private ?array $enum = null;

    private ?string $format = null;

    /**
     * @param int $minLength
     * @return $this
     */
    public function minLength(int $minLength): self
    {
        if ($minLength < 0) {
            throw new InvalidArgumentException('String minLength must be >= 0.');
        }
        $this->minLength = $minLength;
        return $this;
    }

    /**
     * @param int $maxLength
     * @return $this
     */
    public function maxLength(int $maxLength): self
    {
        if ($maxLength < 0) {
            throw new InvalidArgumentException('String maxLength must be >= 0.');
        }
        $this->maxLength = $maxLength;
        return $this;
    }

    /**
     * ECMA-262 regex emitted verbatim as the `pattern` keyword.
     *
     * @param string $pattern
     * @return $this
     */
    public function pattern(string $pattern): self
    {
        $this->pattern = $pattern;
        return $this;
    }

    /**
     * Restrict the value to a fixed list of strings.
     *
     * @param array<int, string> $values
     * @return $this
     */
    public function enum(array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('String enum must have at least one value.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('String enum values must all be strings.');
            }
        }
        $this->enum = array_values($values);
        return $this;
    }

    /**
     * JSON Schema `format` keyword (e.g. `email`, `uri`, `date-time`).
     *
     * @param string $format
     * @return $this
     */
    public function format(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toSchemaArray(): array
    {
        $schema = ['type' => 'string'];
        if ($this->minLength !== null) {
            $schema['minLength'] = $this->minLength;
        }
        if ($this->maxLength !== null) {
            $schema['maxLength'] = $this->maxLength;
        }
        if ($this->pattern !== null) {
            $schema['pattern'] = $this->pattern;
        }
        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        }
        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }
        return $this->withDescription($schema);
    }
}
