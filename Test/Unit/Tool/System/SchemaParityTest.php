<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Tool\System\ConfigGet;
use Magebit\Mcp\Tool\System\StoreInfo;
use Magebit\Mcp\Tool\System\StoreList;
use Magento\Config\Model\Config\Structure;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\StoreConfigManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards that builder-driven schemas for the three core tools stay byte-identical to the raw-array form.
 */
class SchemaParityTest extends TestCase
{
    public function testStoreListSchemaMatches(): void
    {
        $tool = new StoreList(
            $this->createMock(WebsiteRepositoryInterface::class),
            $this->createMock(GroupRepositoryInterface::class),
            $this->createMock(StoreRepositoryInterface::class)
        );

        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include stores with `is_active=0`. Defaults to `false`.',
                ],
                'website_id' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'minItems' => 1,
                    'description' => 'Narrow the output to these website ids '
                        . '(e.g. `[1]` for a single website). Groups and stores '
                        . 'belonging to other websites are dropped.',
                ],
            ],
            'additionalProperties' => false,
        ], $tool->getInputSchema());
    }

    public function testStoreInfoSchemaMatches(): void
    {
        $tool = new StoreInfo(
            $this->createMock(StoreRepositoryInterface::class),
            $this->createMock(StoreConfigManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'store_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numeric store view id (see `system.store.list`).',
                ],
                'store_code' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Store-view code (e.g. `default`).',
                ],
            ],
            'additionalProperties' => false,
        ], $tool->getInputSchema());
    }

    public function testConfigGetSchemaMatches(): void
    {
        $tool = new ConfigGet(
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Structure::class)
        );

        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'pattern' => '^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){1,}$',
                    'description' => 'Slash-separated config path (section/group/field).',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['default', 'websites', 'stores'],
                    'description' => 'Scope level. Defaults to `default`. '
                        . 'Supply `scope_code` with `websites` / `stores`.',
                ],
                'scope_code' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Website / store code when `scope` is '
                        . '`websites` or `stores`.',
                ],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ], $tool->getInputSchema());
    }
}
