<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * A tool the admin turned off looks exactly like a tool that does not exist.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class DisabledToolTest extends McpTestCase
{
    private const DISABLED_PATH = 'magebit_mcp/tools/disabled';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        ConfigState::remember(self::DISABLED_PATH);
        parent::setUp();
        /** @var DisabledTools $disabledTools */
        $disabledTools = Bootstrap::getObjectManager()->get(DisabledTools::class);
        $disabledTools->setDisabled('system.store.list', true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ConfigState::restoreAll();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testDisabledToolAnswersLikeUnregisteredTool(): void
    {
        $disabled = $this->toolsCall('system.store.list');
        $missing = $this->toolsCall('system.no_such_tool');

        $this->assertJsonRpcError($disabled, ErrorCode::TOOL_NOT_FOUND->value, 200);
        $this->assertJsonRpcError($missing, ErrorCode::TOOL_NOT_FOUND->value, 200);
        self::assertSame(
            str_replace('system.no_such_tool', 'X', $this->errorMessage($missing)),
            str_replace('system.store.list', 'X', $this->errorMessage($disabled))
        );

        self::assertNotContains('system_store_list', ToolPayload::toolNames($this->toolsList()));
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param array $response
     * @return string
     */
    private function errorMessage(array $response): string
    {
        $error = $response['body']['error'] ?? null;
        self::assertIsArray($error);

        return (string) ($error['message'] ?? '');
    }
}
