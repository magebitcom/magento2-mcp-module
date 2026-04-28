<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

/**
 * `tools/list` shape + core-tool presence.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class ToolsListTest extends McpTestCase
{
    /**
     * Tools that this module ships and that adminUser's full-perm role grants.
     * Names are emitted in the dot→underscore wire form because Claude.ai's
     * frontend rejects dots — the canonical identity stays internal.
     */
    private const REQUIRED_TOOLS = [
        'system_store_list',
        'system_store_info',
        'system_config_get',
    ];

    protected ?bool $allowWrites = true;

    public function testReturnsCoreTools(): void
    {
        $response = $this->toolsList();

        $this->assertJsonRpcSuccess($response);
        $body = $response['body'];
        self::assertIsArray($body);
        $tools = $body['result']['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertGreaterThanOrEqual(13, count($tools), sprintf(
            'Expected at least 13 tools; got %d.',
            count($tools)
        ));

        $byName = [];
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertIsString($tool['name']);
            self::assertIsString($tool['description']);
            self::assertIsArray($tool['inputSchema']);
            $byName[$tool['name']] = $tool;
        }

        foreach (self::REQUIRED_TOOLS as $required) {
            self::assertArrayHasKey($required, $byName, sprintf(
                'Core tool "%s" missing from tools/list.',
                $required
            ));
        }
    }
}
