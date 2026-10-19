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
 * Checks the shared security test helpers against the live endpoint.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class AuditLogReaderTest extends McpTestCase
{
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
    public function testLatestReturnsRowForJustMadeCall(): void
    {
        $this->toolsCall('system.whoami');
        $row = AuditLogReader::latest('system.whoami', $this->tokenRowId);
        self::assertIsArray($row);
        self::assertSame('ok', $row['response_status']);
        self::assertIsNumeric($row['token_id']);
        self::assertSame($this->tokenRowId, (int) $row['token_id']);
    }

    /**
     * @return void
     */
    public function testConfigStateRestoresAMissingRowByDeletingIt(): void
    {
        $path = 'magebit_mcp/mcpsec/probe';
        self::assertFalse(ConfigState::hasRow($path));
        ConfigState::set($path, '1');
        self::assertSame('1', ConfigState::read($path));
        ConfigState::restoreAll();
        self::assertFalse(ConfigState::hasRow($path));
    }
}
