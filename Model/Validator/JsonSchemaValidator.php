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
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;

/**
 * Thin adapter around opis/json-schema. MCP tools declare schemas as PHP arrays;
 * we round-trip via JSON to hand opis its expected object shape.
 */
class JsonSchemaValidator
{
    private const MESSAGE_MAX_PAIRS = 3;
    private const MAX_ERRORS = 20;

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
        $dataJson = json_encode((object) $this->normaliseValue($schema, $data), JSON_UNESCAPED_SLASHES);
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

        $result = $this->validateAll($dataObj, $schemaObj);
        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        if ($error === null) {
            throw new SchemaValidationException('Schema validation failed (no error details).');
        }

        $formatter = new ErrorFormatter();
        $errors = $this->flattenErrors($formatter->format(
            $error,
            true,
            fn (ValidationError $e, string $message): string => $this->formatErrorMessage($formatter, $e, $message)
        ));

        throw new SchemaValidationException($this->buildMessage($errors), $errors);
    }

    /**
     * Collect every failing path, not only the first. The injected validator may be shared,
     * so its own limits are restored afterwards.
     *
     * @param mixed $data
     * @param object|bool|string $schema
     * @return ValidationResult
     */
    private function validateAll(mixed $data, object|bool|string $schema): ValidationResult
    {
        $maxErrors = $this->validator->getMaxErrors();
        $stopAtFirstError = $this->validator->getStopAtFirstError();
        $this->validator->setMaxErrors(max($maxErrors, self::MAX_ERRORS));
        $this->validator->setStopAtFirstError(false);
        try {
            return $this->validator->validate($data, $schema);
        } finally {
            $this->validator->setMaxErrors($maxErrors);
            $this->validator->setStopAtFirstError($stopAtFirstError);
        }
    }

    /**
     * Opis lists a declared property as "additional" when that property itself failed
     * (it is then never marked as checked). Name only the really undeclared ones, and
     * return '' when none is left so the entry is dropped.
     *
     * @param ErrorFormatter $formatter
     * @param ValidationError $error
     * @param string $message
     * @return string
     */
    private function formatErrorMessage(ErrorFormatter $formatter, ValidationError $error, string $message): string
    {
        $schema = $error->schema()->info()->data();
        $args = $error->args();
        if ($error->keyword() !== 'additionalProperties'
            || $message !== $error->message()
            || !is_object($schema)
            || !isset($schema->properties)
            || !is_object($schema->properties)
            || !isset($args['properties'])
            || !is_array($args['properties'])
        ) {
            return $formatter->formatErrorMessage($error, $message);
        }

        $declared = array_map('strval', array_keys(get_object_vars($schema->properties)));
        $named = array_filter($args['properties'], static fn ($name): bool => is_string($name));
        $unknown = array_values(array_diff($named, $declared));
        if ($unknown === []) {
            return '';
        }
        return str_replace('{properties}', implode(', ', $unknown), $message);
    }

    /**
     * One entry per failing path, several messages for the same path joined into one string.
     *
     * @param array<int|string, mixed> $formatted
     * @return array<int|string, mixed>
     */
    private function flattenErrors(array $formatted): array
    {
        $errors = [];
        foreach ($formatted as $path => $messages) {
            if (!is_array($messages)) {
                $errors[$path] = $messages;
                continue;
            }
            $strings = array_values(array_filter(
                $messages,
                static fn ($message): bool => is_string($message) && $message !== ''
            ));
            if ($strings !== []) {
                $errors[$path] = implode('. ', $strings);
            }
        }
        return $errors;
    }

    /**
     * Name the failing arguments in the message itself; many clients show only the message.
     *
     * @param array<int|string, mixed> $errors
     * @return string
     */
    private function buildMessage(array $errors): string
    {
        $pairs = [];
        foreach ($errors as $path => $message) {
            if (is_string($message)) {
                $pairs[] = $path . ': ' . $message;
            }
        }
        if ($pairs === []) {
            return 'Schema validation failed.';
        }

        $shown = array_slice($pairs, 0, self::MESSAGE_MAX_PAIRS);
        $more = count($pairs) - count($shown);
        return 'Schema validation failed: ' . implode('; ', $shown)
            . ($more > 0 ? sprintf(' (+%d more)', $more) : '');
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
     * PHP's json_decode(..., true) collapses `{}` to `[]`. Restore empty arrays
     * to stdClass wherever the schema declares `type: object`, recursing through
     * object `properties` and array `items`.
     *
     * @param array<array-key, mixed> $schema
     * @param mixed $value
     * @return mixed
     */
    private function normaliseValue(array $schema, mixed $value): mixed
    {
        // Non-array values pass through unchanged
        if (!is_array($value)) {
            return $value;
        }

        $types = (array) ($schema['type'] ?? []);

        // If schema declares type: object, convert empty arrays to stdClass
        if (in_array('object', $types, true)) {
            if ($value === []) {
                return new \stdClass();
            }
            // Recurse into properties
            $properties = $schema['properties'] ?? null;
            if (is_array($properties)) {
                foreach ($value as $key => $itemValue) {
                    $propSchema = $properties[$key] ?? null;
                    if (is_array($propSchema)) {
                        $value[$key] = $this->normaliseValue($propSchema, $itemValue);
                    }
                }
            }
            return $value;
        }

        // If schema declares type: array with items schema, recurse over items
        if (in_array('array', $types, true)) {
            $itemsSchema = $schema['items'] ?? null;
            if (is_array($itemsSchema)) {
                return array_map(
                    fn($item) => $this->normaliseValue($itemsSchema, $item),
                    $value
                );
            }
        }

        return $value;
    }
}
