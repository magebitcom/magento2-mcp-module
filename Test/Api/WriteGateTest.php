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
 * Write gating: WRITE-mode tools require BOTH `magebit_mcp/general/allow_writes`
 * AND the per-token `allow_writes` flag. The site-wide flag defaults to `1`
 * via etc/config.xml, so flipping the token flag alone is sufficient to
 * exercise the gate.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class WriteGateTest extends McpTestCase
{
    /** setUp() mints a token with allow_writes=false (the default). */
    protected ?bool $allowWrites = false;

    public function testWriteToolBlockedWhenTokenDisallowsWrites(): void
    {
        // `system.cache.flush` is WRITE-mode and adminUser's role grants
        // both its MCP ACL resource and the underlying `Magento_Backend::cache`
        // resource — so the only check that can reject this call is the write gate.
        $response = $this->toolsCall('system.cache.flush', ['all' => true]);

        $this->assertJsonRpcError($response, ErrorCode::WRITE_NOT_ALLOWED->value, 200);
    }

    public function testWriteToolHiddenFromToolsListWhenTokenDisallowsWrites(): void
    {
        // tools/list mirrors the tools/call gate: WRITE tools are filtered out
        // when the caller can't run them.
        $response = $this->toolsList();

        $this->assertJsonRpcSuccess($response);
        $body = $response['body'];
        self::assertIsArray($body);
        $tools = $body['result']['tools'] ?? null;
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        self::assertNotContains('system.cache.flush', $names);
    }
}
