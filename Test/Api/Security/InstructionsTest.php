<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Test\Api\McpTestCase;

/**
 * The `initialize` answer tells the connected AI to use write tools only on an explicit request.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class InstructionsTest extends McpTestCase
{
    /**
     * @return void
     */
    public function testInitializeInstructionsRestrictWrites(): void
    {
        $response = $this->initialize();

        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        self::assertArrayHasKey('instructions', $result, 'initialize sent no server instructions.');
        self::assertIsString($result['instructions']);
        self::assertStringContainsString('only when the user has explicitly asked', $result['instructions']);
    }
}
