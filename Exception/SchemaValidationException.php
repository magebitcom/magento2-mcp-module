<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Exception;

use RuntimeException;

/**
 * Raised by {@see \Magebit\Mcp\Model\Validator\JsonSchemaValidator} when
 * tool call arguments fail their JSON Schema. Carries the formatted opis
 * error structure so callers can surface detail to the client.
 */
class SchemaValidationException extends RuntimeException
{
    /**
     * @param string $message
     * @param array $errors Formatted opis/json-schema error tree.
     * @phpstan-param array<int|string, mixed> $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    /**
     * Retrieve the structured opis error tree for inclusion in a JSON-RPC error.
     *
     * @return array<int|string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
