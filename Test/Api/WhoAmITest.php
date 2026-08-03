<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

/**
 * End-to-end authenticated identity resolution.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class WhoAmITest extends McpTestCase
{
    public function testReturnsBearerIdentity(): void
    {
        $response = $this->toolsCall('system.whoami');

        $this->assertJsonRpcSuccess($response);
        $body = $response['body'];
        self::assertIsArray($body);
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? true);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $first = $content[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('text', $first['type'] ?? null);
        $text = $first['text'] ?? null;
        self::assertIsString($text);
        $payload = json_decode($text, true);
        self::assertIsArray($payload);

        self::assertGreaterThan(0, $payload['admin_user_id'] ?? 0);
        self::assertSame('adminUser', $payload['username'] ?? null);
        self::assertIsString($payload['email'] ?? null);
        self::assertStringContainsString('@', $payload['email']);
        self::assertIsString($payload['token_name'] ?? null);
        self::assertStringStartsWith('api-functional adminUser @ ', $payload['token_name']);
        self::assertIsBool($payload['allow_writes'] ?? null);
    }
}
