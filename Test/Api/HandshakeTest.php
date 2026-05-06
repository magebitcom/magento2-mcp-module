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

    /**
     * Fresh install — no per-field overrides — still ships sensible
     * defaults: a description and instructions string (from `etc/config.xml`)
     * and a websiteUrl falling back to the store's base URL. `icons` stays
     * absent because there is no shipped default icon URL.
     */
    public function testInitializeServerInfoCarriesShippedDefaults(): void
    {
        $response = $this->initialize();

        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        $serverInfo = $result['serverInfo'] ?? null;
        self::assertIsArray($serverInfo);
        self::assertArrayHasKey('name', $serverInfo);
        self::assertArrayHasKey('version', $serverInfo);
        self::assertArrayHasKey('description', $serverInfo);
        self::assertNotEmpty($serverInfo['description']);
        self::assertArrayHasKey('websiteUrl', $serverInfo);
        self::assertNotEmpty($serverInfo['websiteUrl']);
        self::assertArrayNotHasKey('icons', $serverInfo);
        self::assertArrayHasKey('instructions', $result);
        self::assertNotEmpty($result['instructions']);
    }

    /**
     * Every Server Info field populated → response surfaces them all
     * verbatim, on the right key, in the right place (`icons` is an array
     * of one entry; `instructions` is top-level, not nested).
     *
     * @magentoConfigFixture default/magebit_mcp/server_info/title Acme Storefront
     * @magentoConfigFixture default/magebit_mcp/server_info/description Magento store for Acme Inc.
     * @magentoConfigFixture default/magebit_mcp/server_info/website_url https://acme.example.com
     * @magentoConfigFixture default/magebit_mcp/server_info/icon_url https://acme.example.com/icon.svg
     * @magentoConfigFixture default/magebit_mcp/server_info/icon_mime_type image/svg+xml
     * @magentoConfigFixture default/magebit_mcp/server_info/icon_sizes any
     * @magentoConfigFixture default/magebit_mcp/server_info/instructions Use product SKUs not IDs.
     */
    public function testInitializeServerInfoSurfacesAllConfiguredFields(): void
    {
        $response = $this->initialize();

        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        $serverInfo = $result['serverInfo'] ?? null;
        self::assertIsArray($serverInfo);
        self::assertSame('Acme Storefront', $serverInfo['title'] ?? null);
        self::assertSame('Magento store for Acme Inc.', $serverInfo['description'] ?? null);
        self::assertSame('https://acme.example.com', $serverInfo['websiteUrl'] ?? null);
        self::assertSame(
            [['src' => 'https://acme.example.com/icon.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']]],
            $serverInfo['icons'] ?? null
        );
        self::assertSame('Use product SKUs not IDs.', $result['instructions'] ?? null);
    }

    /**
     * URL without a MIME type drops the `icons` entry entirely — a URL
     * alone isn't enough metadata to publish, and guessing a MIME type
     * would mis-advertise the icon to clients.
     *
     * @magentoConfigFixture default/magebit_mcp/server_info/icon_url https://acme.example.com/icon.svg
     */
    public function testInitializeOmitsIconsWhenMimeTypeUnset(): void
    {
        $response = $this->initialize();

        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        $serverInfo = $result['serverInfo'] ?? null;
        self::assertIsArray($serverInfo);
        self::assertArrayNotHasKey('icons', $serverInfo);
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
