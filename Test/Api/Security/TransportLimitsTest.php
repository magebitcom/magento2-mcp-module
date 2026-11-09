<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Test\Api\McpTestCase;

/**
 * Request bodies over the size cap (256 KiB by default) are refused, with or without
 * a Content-Length header. A body exactly at the cap still works.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class TransportLimitsTest extends McpTestCase
{
    private const CAP_PATH = 'magebit_mcp/general/max_body_kb';
    private const CAP_BYTES = 262144;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (ConfigState::hasRow(self::CAP_PATH)) {
            self::markTestSkipped('The body size cap was changed on this install.');
        }
    }

    /**
     * @return void
     */
    public function testBodyOverCapIsRejectedWith413(): void
    {
        $response = $this->request([], ['body' => $this->pingBodyOfLength(self::CAP_BYTES + 1)]);

        $this->assertJsonRpcError($response, ErrorCode::INVALID_REQUEST->value, 413);
    }

    /**
     * @return void
     */
    public function testChunkedBodyOverCapIsRejected(): void
    {
        // curl drops Content-Length when this header is set. If nginx answers 413
        // itself the body is not JSON, so only the status is checked.
        $response = $this->request([], [
            'body' => $this->pingBodyOfLength(self::CAP_BYTES + 1),
            'headers' => ['Transfer-Encoding' => 'chunked'],
        ]);

        self::assertSame(413, $response['status']);
    }

    /**
     * @return void
     */
    public function testBodyAtCapIsAccepted(): void
    {
        $response = $this->request([], ['body' => $this->pingBodyOfLength(self::CAP_BYTES)]);

        $this->assertJsonRpcSuccess($response);
    }

    /**
     * A valid `ping` request padded to exactly $bytes bytes.
     *
     * @param int $bytes
     * @return string
     */
    private function pingBodyOfLength(int $bytes): string
    {
        $prefix = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"';
        $suffix = '"}}';

        return $prefix . str_repeat('a', $bytes - strlen($prefix) - strlen($suffix)) . $suffix;
    }
}
