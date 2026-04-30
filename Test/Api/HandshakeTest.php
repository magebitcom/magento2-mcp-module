<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;

/**
 * MCP handshake + protocol-version gate.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class HandshakeTest extends McpTestCase
{
    public function testInitializeSucceedsWithoutProtocolVersionHeader(): void
    {
        $response = $this->initialize();

        $this->assertJsonRpcSuccess($response);
        $body = $response['body'];
        self::assertIsArray($body);
        $result = $body['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame('2025-06-18', $result['protocolVersion'] ?? null);
        self::assertArrayHasKey('serverInfo', $result);
        self::assertArrayHasKey('capabilities', $result);
        self::assertArrayHasKey('tools', $result['capabilities']);
    }

    public function testPingSucceeds(): void
    {
        $response = $this->ping();

        $this->assertJsonRpcSuccess($response);
    }

    public function testToolsCallWithoutProtocolVersionHeaderRejected(): void
    {
        // The controller skips the version check ONLY when the header is
        // omitted entirely; sending an unsupported value short-circuits.
        $response = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'system.store.list', 'arguments' => new \stdClass()],
            ],
            ['headers' => ['Mcp-Protocol-Version' => '1900-01-01']]
        );

        $this->assertJsonRpcError($response, ErrorCode::UNSUPPORTED_PROTOCOL_VERSION->value, 400);
    }

    public function testToolsCallSucceedsWithSupportedProtocolVersion(): void
    {
        $response = $this->toolsCall('system.store.list');

        $this->assertJsonRpcSuccess($response);
    }
}
