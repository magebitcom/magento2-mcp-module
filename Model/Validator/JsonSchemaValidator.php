<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

use Magebit\Mcp\Exception\SchemaValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Thin adapter around opis/json-schema. MCP tools declare schemas as PHP arrays;
 * we round-trip via JSON to hand opis its expected object shape.
 */
class JsonSchemaValidator
{
    /**
     * @param Validator $validator
     */
    public function __construct(
        private readonly Validator $validator
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     * @return void
     * @throws SchemaValidationException
     */
    public function validate(array $schema, array $data): void
    {
        $schemaJson = json_encode($this->normaliseEmptyPropertyObjects($schema), JSON_UNESCAPED_SLASHES);
        $dataJson = json_encode((object) $this->normaliseEmptyObjectData($schema, $data), JSON_UNESCAPED_SLASHES);
        if ($schemaJson === false || $dataJson === false) {
            throw new SchemaValidationException('Unable to encode schema or data as JSON.');
        }

        try {
            $schemaObj = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
            $dataObj = json_decode($dataJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaValidationException('Unable to parse schema or data: ' . $e->getMessage());
        }

        if (!is_object($schemaObj) && !is_bool($schemaObj) && !is_string($schemaObj)) {
            throw new SchemaValidationException('Schema must decode to an object, boolean, or string.');
        }

        $result = $this->validator->validate($dataObj, $schemaObj);
        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        if ($error === null) {
            throw new SchemaValidationException('Schema validation failed (no error details).');
        }

        $formatter = new ErrorFormatter();
        /** @var array<int|string, mixed> $errors */
        $errors = $formatter->format($error, false);

        throw new SchemaValidationException(
            'Schema validation failed.',
            $errors
        );
    }

    /**
     * Empty PHP arrays at `properties` keys encode to `[]` (JSON array),
     * which Opis rejects. Convert to stdClass so they encode as `{}`.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function normaliseEmptyPropertyObjects(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && is_array($value) && $value === []) {
                $out[$key] = new \stdClass();
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->normaliseEmptyPropertyObjects($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * PHP's json_decode(..., true) collapses `{}` to `[]`. Where the schema
     * declares `type: object`, restore empty arrays to stdClass so opis sees
     * the object the client actually sent.
     *
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function normaliseEmptyObjectData(array $schema, array $data): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            $propSchema = $properties[$key] ?? null;
            if (!is_array($propSchema) || !is_array($value)) {
                continue;
            }
            $types = (array) ($propSchema['type'] ?? []);
            if (!in_array('object', $types, true)) {
                continue;
            }
            $data[$key] = $value === []
                ? new \stdClass()
                : $this->normaliseEmptyObjectData($propSchema, $value);
        }
        return $data;
    }
}
