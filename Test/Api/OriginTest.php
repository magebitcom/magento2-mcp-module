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
 * Origin allowlist + DNS-rebinding defense.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class OriginTest extends McpTestCase
{
    public function testAllowedOriginPasses(): void
    {
        // Default allowlist seeds `https://localhost*`; setUp() picks that.
        $response = $this->toolsList();

        $this->assertJsonRpcSuccess($response);
    }

    public function testDisallowedOriginIsRejected(): void
    {
        $this->origin = 'https://evil.example.com';

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::INVALID_ORIGIN->value, 403);
    }

    public function testLiteralNullOriginIsRejected(): void
    {
        // Sandboxed iframes / data: URIs send literal "null" — exactly the
        // shape the validator exists to block.
        $this->origin = 'null';

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::INVALID_ORIGIN->value, 403);
    }

    public function testMissingOriginIsAccepted(): void
    {
        // curl, Claude Desktop, and ChatGPT Desktop omit the Origin header
        // entirely — the validator accepts that path.
        $response = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['headers' => ['Origin' => '']]
        );

        $this->assertJsonRpcSuccess($response);
    }

    public function testHostBoundaryIsEnforced(): void
    {
        // `https://localhost.attacker.com` must NOT match `https://localhost*`.
        $this->origin = 'https://localhost.attacker.com';

        $response = $this->toolsList();

        $this->assertJsonRpcError($response, ErrorCode::INVALID_ORIGIN->value, 403);
    }
}
