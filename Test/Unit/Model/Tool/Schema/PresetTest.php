<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Schema;

use Magebit\Mcp\Model\Tool\Schema\Preset\FieldSelection;
use Magebit\Mcp\Model\Tool\Schema\Preset\Filters;
use Magebit\Mcp\Model\Tool\Schema\Preset\Pagination;
use Magebit\Mcp\Model\Tool\Schema\Preset\Sort;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use PHPUnit\Framework\TestCase;

class PresetTest extends TestCase
{
    public function testPaginationEmitsPageAndPageSize(): void
    {
        $schema = Schema::object()
            ->with(Pagination::maxPageSize(200))
            ->toArray();

        self::assertSame([
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => '1-based page number.',
            ],
            'page_size' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 200,
                'description' => 'Rows per page (capped at 200).',
            ],
        ], $schema['properties']);
    }

    public function testSortEmitsSortByAndSortDir(): void
    {
        $schema = Schema::object()
            ->with(Sort::fields(['created_at', 'updated_at', 'entity_id']))
            ->toArray();

        self::assertSame([
            'sort_by' => [
                'type' => 'string',
                'enum' => ['created_at', 'updated_at', 'entity_id'],
                'description' => 'Sort field. Defaults to `created_at`.',
            ],
            'sort_dir' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'description' => 'Sort direction. Defaults to `desc`.',
            ],
        ], $schema['properties']);
    }

    public function testFieldSelectionDefaultEmitsFieldsAndExclude(): void
    {
        $schema = Schema::object()
            ->with(FieldSelection::default())
            ->toArray();

        self::assertSame([
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Whitelist of resolver keys per row.',
            ],
            'exclude' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Resolver keys to drop from each row.',
            ],
        ], $schema['properties']);
    }

    public function testFiltersEmitsOpenBagObject(): void
    {
        $schema = Schema::object()
            ->with(Filters::describing('Filter clauses. Built-in keys: status, state.'))
            ->toArray();

        self::assertSame([
            'filters' => [
                'type' => 'object',
                'description' => 'Filter clauses. Built-in keys: status, state.',
            ],
        ], $schema['properties']);
    }

    public function testAllFourPresetsComposeToTheListToolShape(): void
    {
        $schema = Schema::object()
            ->with(Filters::describing('Built-in keys: status, state.'))
            ->with(Sort::fields(['created_at']))
            ->with(Pagination::maxPageSize(100))
            ->with(FieldSelection::default())
            ->toArray();

        self::assertSame(
            ['filters', 'sort_by', 'sort_dir', 'page', 'page_size', 'fields', 'exclude'],
            array_keys($schema['properties'])
        );
        self::assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertArrayNotHasKey('required', $schema);
    }
}
