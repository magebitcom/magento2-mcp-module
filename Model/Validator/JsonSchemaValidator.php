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
 * Thin adapter around opis/json-schema. All MCP tools declare their argument
 * schema as a PHP array; we round-trip via JSON to hand opis the object shape
 * it expects and raise a uniform {@see SchemaValidationException} on failure.
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
     * Validate `$data` against `$schema` or throw on any failure.
     *
     * @param array $schema
     * @param array $data
     * @phpstan-param array<string, mixed> $schema
     * @phpstan-param array<string, mixed> $data
     * @return void
     * @throws SchemaValidationException
     */
    public function validate(array $schema, array $data): void
    {
        $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $dataJson = json_encode((object) $data, JSON_UNESCAPED_SLASHES);
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
}
