<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

/**
 * Best-effort coercion of tool arguments toward the declared input schema,
 * for MCP clients/bridges that stringify typed values. Uncoercible values
 * pass through untouched — the validator still rejects them with details.
 */
class ArgumentCoercer
{
    /**
     * @param array<array-key, mixed> $schema
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function coerce(array $schema, array $data): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            $propSchema = $properties[$key] ?? null;
            if (is_array($propSchema)) {
                $data[$key] = $this->coerceValue($propSchema, $value);
            }
        }
        return $data;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param mixed $value
     * @return mixed
     */
    private function coerceValue(array $schema, mixed $value): mixed
    {
        $types = (array) ($schema['type'] ?? []);
        if (is_array($value)) {
            if (in_array('object', $types, true)) {
                /** @var array<string, mixed> $value */
                return $this->coerce($schema, $value);
            }
            if (in_array('array', $types, true) && is_array($schema['items'] ?? null)) {
                /** @var array<array-key, mixed> $items */
                $items = $schema['items'];
                return array_map(fn (mixed $item): mixed => $this->coerceValue($items, $item), $value);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (in_array('integer', $types, true) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (in_array('number', $types, true) && is_numeric($value)) {
            return (float) $value;
        }
        if (in_array('boolean', $types, true)) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool;
            }
        }
        return $value;
    }
}
