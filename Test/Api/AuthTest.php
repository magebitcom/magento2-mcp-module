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
 * Bearer auth: missing, malformed, revoked, expired tokens.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class AuthTest extends McpTestCase
{
    public function testMissingAuthorizationHeaderReturns401(): void
    {
        // Drop the bearer issued by setUp() and skip the Authorization header.
        $response = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['skipAuthorizationHeader' => true]
        );

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
        self::assertArrayHasKey('www-authenticate', $response['headers']);
        self::assertStringStartsWith('Bearer', $response['headers']['www-authenticate']);
        self::assertStringContainsString(
            'resource_metadata="',
            $response['headers']['www-authenticate']
        );
        self::assertStringContainsString(
            '/mcp/oauth/protected-resource-metadata',
            $response['headers']['www-authenticate']
        );
    }

    public function testInvalidBearerReturnsUnauthorized(): void
    {
        $this->bearerToken = 'this-is-not-a-valid-token';

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
    }

    public function testMalformedAuthorizationHeaderReturnsUnauthorized(): void
    {
        // Missing the "Bearer " prefix.
        $response = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['headers' => ['Authorization' => 'Basic Zm9vOmJhcg==']]
        );

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
    }

    public function testRevokedTokenReturnsUnauthorized(): void
    {
        // Drop the auto-issued token and replace with a revoked one.
        TokenFixture::delete($this->tokenRowId ?? 0);
        $issued = TokenFixture::issueRevoked('adminUser');
        $this->bearerToken = $issued['token'];
        $this->tokenRowId = $issued['id'];

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
    }

    public function testExpiredTokenReturnsUnauthorized(): void
    {
        TokenFixture::delete($this->tokenRowId ?? 0);
        $issued = TokenFixture::issueExpired('adminUser');
        $this->bearerToken = $issued['token'];
        $this->tokenRowId = $issued['id'];

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
    }
}
