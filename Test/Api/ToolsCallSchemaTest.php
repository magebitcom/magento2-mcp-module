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
 * Input-schema validation on `tools/call`.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class ToolsCallSchemaTest extends McpTestCase
{
    public function testInvalidArgumentTypeIsRejected(): void
    {
        // `system.store.list` declares `include_inactive: boolean` — a string
        // here forces the JSON-Schema validator to reject the call.
        $response = $this->toolsCall('system.store.list', ['include_inactive' => 'definitely-not-a-bool']);

        $this->assertJsonRpcError($response, ErrorCode::SCHEMA_VALIDATION_FAILED->value, 200);

        $body = $response['body'];
        self::assertIsArray($body);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        self::assertArrayHasKey('data', $error);
        self::assertIsArray($error['data']);
        self::assertArrayHasKey('errors', $error['data']);
    }

    public function testUnknownToolReturnsToolNotFound(): void
    {
        $response = $this->toolsCall('does.not.exist');

        $this->assertJsonRpcError($response, ErrorCode::TOOL_NOT_FOUND->value, 200);
    }

    public function testMissingNameParamReturnsInvalidParams(): void
    {
        $response = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['arguments' => new \stdClass()],
        ]);

        $this->assertJsonRpcError($response, ErrorCode::INVALID_PARAMS->value, 200);
    }
}
