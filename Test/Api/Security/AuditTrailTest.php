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
 * Refused and unauthenticated requests still leave a row in the audit log.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class AuditTrailTest extends McpTestCase
{
    /**
     * @return void
     */
    public function testRefusedCallIsAuditedWithErrorCode(): void
    {
        $response = $this->toolsCall('system.no_such_tool');

        $this->assertJsonRpcError($response, ErrorCode::TOOL_NOT_FOUND->value, 200);
        $audit = AuditLogReader::latest('system.no_such_tool', $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for the refused call.');
        self::assertSame('-32010', $audit['error_code'] ?? null);
        self::assertSame('error', $audit['response_status'] ?? null);
    }

    /**
     * @return void
     */
    public function testUnauthenticatedRequestIsAudited(): void
    {
        $before = AuditLogReader::maxId();

        $response = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['skipAuthorizationHeader' => true]
        );

        $this->assertJsonRpcError($response, ErrorCode::UNAUTHORIZED->value, 401);
        $audit = AuditLogReader::latestForMethod('(request)', null, $before);
        self::assertNotNull($audit, 'Expected an audit row for the unauthenticated request.');
        self::assertSame('-32001', $audit['error_code'] ?? null);
        self::assertNull($audit['token_id'] ?? null);
    }
}
