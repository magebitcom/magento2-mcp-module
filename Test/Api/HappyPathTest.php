<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

/**
 * End-to-end happy path: the same call a real MCP client would make
 * after handshake, asserting on the actual content shape returned by
 * `system.store.list`.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class HappyPathTest extends McpTestCase
{
    public function testToolsCallStoreListReturnsContentBlock(): void
    {
        $response = $this->toolsCall('system.store.list');

        $this->assertJsonRpcSuccess($response);
        $body = $response['body'];
        self::assertIsArray($body);
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame(false, $result['isError'] ?? null);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        self::assertNotEmpty($content);

        $first = $content[0];
        self::assertIsArray($first);
        self::assertSame('text', $first['type'] ?? null);
        self::assertArrayHasKey('text', $first);
        self::assertIsString($first['text']);

        $payload = json_decode($first['text'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('websites', $payload);
        self::assertArrayHasKey('groups', $payload);
        self::assertArrayHasKey('stores', $payload);
        self::assertNotEmpty($payload['stores'], 'Expected at least the default store view.');

        $defaultStore = $payload['stores'][0];
        self::assertIsArray($defaultStore);
        self::assertArrayHasKey('id', $defaultStore);
        self::assertArrayHasKey('code', $defaultStore);
        self::assertArrayHasKey('name', $defaultStore);
    }
}
