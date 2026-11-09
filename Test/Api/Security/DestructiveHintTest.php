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
 * Tools that delete, cancel, refund or reset data tell the client to ask the user first.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class DestructiveHintTest extends McpTestCase
{
    private const DESTRUCTIVE_NAME = '/_(delete|cancel|reset_password|apply_all)$|_credit_memo_create$/';

    protected ?bool $allowWrites = true;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        ConfigState::set('magebit_mcp/general/allow_writes', '1');
        parent::setUp();
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
    public function testDestructiveToolsDeclareDestructiveHint(): void
    {
        $response = $this->toolsList();
        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        $tools = $result['tools'] ?? null;
        self::assertIsArray($tools);

        $matches = [];
        $misses = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }
            if (preg_match(self::DESTRUCTIVE_NAME, $tool['name']) !== 1) {
                continue;
            }
            $matches[] = $tool['name'];
            $annotations = $tool['annotations'] ?? null;
            if (!is_array($annotations) || ($annotations['destructiveHint'] ?? null) !== true) {
                $misses[] = $tool['name'];
            }
        }

        self::assertGreaterThan(0, count($matches), 'Expected destructive tools in tools/list.');
        self::assertSame([], $misses, 'Destructive tools without destructiveHint.');
    }
}
