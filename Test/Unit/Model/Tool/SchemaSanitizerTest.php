<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchemaSanitizerTest extends TestCase
{
    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private SchemaSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sanitizer = new SchemaSanitizer($this->logger);
    }

    public function testPassesThroughSchemaWithoutForbiddenKeys(): void
    {
        $this->logger->expects(self::never())->method('warning');

        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'email' => ['type' => 'string'],
            ],
            'required' => ['id'],
        ];

        self::assertSame($schema, $this->sanitizer->sanitize('test.tool', $schema));
    }

    public function testStripsTopLevelOneOfAndLogsWarning(): void
    {
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('oneOf'));

        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'oneOf' => [
                ['required' => ['id']],
                ['required' => ['email']],
            ],
        ];

        $expected = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ];

        self::assertSame($expected, $this->sanitizer->sanitize('test.tool', $schema));
    }

    public function testStripsNestedAnyOfFromProperty(): void
    {
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('properties.street'));

        $schema = [
            'type' => 'object',
            'properties' => [
                'street' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => 'Street.',
                ],
            ],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'street' => [
                    'description' => 'Street.',
                ],
            ],
        ];

        self::assertSame($expected, $this->sanitizer->sanitize('test.tool', $schema));
    }

    public function testStripsAllThreeKeywordsInDeeplyNestedSchema(): void
    {
        $this->logger->expects(self::exactly(3))->method('warning');

        $schema = [
            'type' => 'object',
            'properties' => [
                'field' => [
                    'allOf' => [['type' => 'string']],
                    'type' => 'object',
                    'properties' => [
                        'sub' => [
                            'oneOf' => [['type' => 'integer']],
                        ],
                    ],
                ],
            ],
            'anyOf' => [],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'field' => [
                    'type' => 'object',
                    'properties' => [
                        'sub' => [],
                    ],
                ],
            ],
        ];

        self::assertSame($expected, $this->sanitizer->sanitize('test.tool', $schema));
    }

    public function testEmptySchemaIsLeftAlone(): void
    {
        $this->logger->expects(self::never())->method('warning');
        self::assertSame([], $this->sanitizer->sanitize('test.tool', []));
    }

    public function testEmptyPropertiesBecomeStdClassSoJsonEncodesAsObject(): void
    {
        $sanitized = $this->sanitizer->sanitize('test.tool', [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ]);

        self::assertInstanceOf(\stdClass::class, $sanitized['properties']);
        self::assertSame(
            '{"type":"object","properties":{},"additionalProperties":false}',
            (string) json_encode($sanitized, JSON_UNESCAPED_SLASHES)
        );
    }

    public function testEmptyPropertiesNormalizedAtNestedDepth(): void
    {
        $sanitized = $this->sanitizer->sanitize('test.tool', [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ]);

        $properties = $sanitized['properties'];
        self::assertIsArray($properties);
        $address = $properties['address'] ?? null;
        self::assertIsArray($address);
        self::assertInstanceOf(\stdClass::class, $address['properties']);
    }

    public function testDropsIntegerEnumAndFoldsValuesIntoDescription(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'integer',
                    'enum' => [1, 2],
                    'description' => 'Product status.',
                ],
            ],
            'required' => ['status'],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'integer',
                    'description' => 'Product status. Allowed values: 1, 2.',
                ],
            ],
            'required' => ['status'],
        ];

        self::assertSame($expected, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testValidationSchemaKeepsIntegerEnum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'integer', 'enum' => [1, 2]],
            ],
        ];

        self::assertSame($schema, $this->sanitizer->sanitize('test.tool', $schema));
    }

    public function testDroppedEnumWithoutDescriptionGetsOne(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'visibility' => ['type' => 'integer', 'enum' => [1, 2, 3, 4]],
            ],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'visibility' => [
                    'type' => 'integer',
                    'description' => 'Allowed values: 1, 2, 3, 4.',
                ],
            ],
        ];

        self::assertSame($expected, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testKeepsStringEnumUntouched(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'sort_dir' => [
                    'type' => 'string',
                    'enum' => ['asc', 'desc'],
                    'description' => 'Sort direction.',
                ],
            ],
        ];

        self::assertSame($schema, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testDropsEnumNestedInsideArrayItems(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'backorders' => [
                                'type' => 'integer',
                                'enum' => [0, 1, 2],
                                'description' => '0 = no, 1 = allow.',
                            ],
                        ],
                        'required' => ['sku'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'backorders' => [
                                'type' => 'integer',
                                'description' => '0 = no, 1 = allow. Allowed values: 0, 1, 2.',
                            ],
                        ],
                        'required' => ['sku'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];

        self::assertSame($expected, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testDropsEnumMixingStringsAndNumbers(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['all', 0]],
            ],
        ];

        $expected = [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'description' => 'Allowed values: "all", 0.',
                ],
            ],
        ];

        self::assertSame($expected, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testLeavesPropertyNamedEnumAlone(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'enum' => ['type' => 'string', 'description' => 'A field named enum.'],
            ],
            'required' => ['enum'],
        ];

        self::assertSame($schema, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }

    public function testLeavesPropertyNamedEnumWithNumericKeywordsAlone(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'enum' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];

        self::assertSame($schema, $this->sanitizer->sanitizeForClient('test.tool', $schema));
    }
}
