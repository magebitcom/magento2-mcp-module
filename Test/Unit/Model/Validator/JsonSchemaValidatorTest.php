<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Validator;

use Magebit\Mcp\Exception\SchemaValidationException;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class JsonSchemaValidatorTest extends TestCase
{
    /**
     * @var JsonSchemaValidator
     */
    private JsonSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonSchemaValidator(new Validator());
    }

    public function testAcceptsValidInput(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'increment_id' => ['type' => 'string'],
            ],
            'required' => ['increment_id'],
            'additionalProperties' => false,
        ];

        $this->validator->validate($schema, ['increment_id' => '000000001']);

        // No exception means pass.
        $this->assertTrue(true);
    }

    public function testRejectsMissingRequiredField(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'increment_id' => ['type' => 'string'],
            ],
            'required' => ['increment_id'],
        ];

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('Schema validation failed');

        $this->validator->validate($schema, []);
    }

    public function testRejectsTypeMismatch(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'increment_id' => ['type' => 'string'],
            ],
            'required' => ['increment_id'],
        ];

        $this->expectException(SchemaValidationException::class);

        $this->validator->validate($schema, ['increment_id' => 123]);
    }

    public function testRejectsAdditionalProperties(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'increment_id' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];

        $this->expectException(SchemaValidationException::class);

        $this->validator->validate($schema, [
            'increment_id' => '000000001',
            'customer_email' => 'spy@example.com',
        ]);
    }

    public function testErrorsExposeStructuredDetails(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'increment_id' => ['type' => 'string'],
            ],
            'required' => ['increment_id'],
        ];

        try {
            $this->validator->validate($schema, []);
            $this->fail('Expected SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertNotEmpty($e->getErrors(), 'Formatted errors array should not be empty.');
        }
    }
}
