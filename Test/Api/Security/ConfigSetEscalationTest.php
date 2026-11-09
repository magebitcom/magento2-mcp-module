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
 * `system.config.set` with a write token cannot touch protected or secret settings,
 * even when an admin put them on the allowlist.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class ConfigSetEscalationTest extends McpTestCase
{
    private const ALLOWLIST = [
        'magebit_mcp/general/allow_writes',
        'magebit_mcp/config_write/allowed_paths',
        'admin/security/session_lifetime',
        'payment/checkmo/active',
        'web/secure/base_url',
        'dev/debug/template_hints_storefront',
        'oauth/consumer/enable_integration_as_bearer',
        'carriers/fedex/account',
        'general/store_information/name',
    ];

    protected ?bool $allowWrites = true;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        ConfigState::set('magebit_mcp/general/allow_writes', '1');
        ConfigState::set('magebit_mcp/config_write/enabled', '1');
        ConfigState::set('magebit_mcp/config_write/allowed_paths', implode("\n", self::ALLOWLIST));
        foreach (self::ALLOWLIST as $path) {
            ConfigState::remember($path);
        }
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
     * @dataProvider protectedPaths
     * @param string $path
     * @param string $value
     * @return void
     */
    public function testProtectedPathIsRefusedEvenWhenAllowlisted(string $path, string $value): void
    {
        $hadRow = ConfigState::hasRow($path);
        $before = ConfigState::read($path);

        $response = $this->toolsCall('system.config.set', ['path' => $path, 'value' => $value]);

        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        self::assertStringContainsString('is protected by', $this->errorMessage($response));
        $this->assertAuditCode('-32011');
        self::assertSame($hadRow, ConfigState::hasRow($path), 'Row presence changed for ' . $path);
        self::assertSame($before, ConfigState::read($path), 'Value changed for ' . $path);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function protectedPaths(): array
    {
        return [
            ['magebit_mcp/general/allow_writes', '0'],
            ['magebit_mcp/config_write/allowed_paths', 'x'],
            ['admin/security/session_lifetime', '999999'],
            ['payment/checkmo/active', '0'],
            ['web/secure/base_url', 'https://evil.example/'],
            ['dev/debug/template_hints_storefront', '1'],
            ['oauth/consumer/enable_integration_as_bearer', '1'],
        ];
    }

    /**
     * @return void
     */
    public function testSensitiveFieldIsRefused(): void
    {
        // An obscure (secret) field that is not under any protected prefix.
        $path = 'carriers/fedex/account';
        $hadRow = ConfigState::hasRow($path);
        $before = ConfigState::read($path);

        $response = $this->toolsCall('system.config.set', ['path' => $path, 'value' => '123456']);

        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        self::assertStringContainsString(
            'cannot be written over MCP (field_type_sensitive',
            $this->errorMessage($response)
        );
        $this->assertAuditCode('-32011');
        self::assertSame($hadRow, ConfigState::hasRow($path));
        self::assertSame($before, ConfigState::read($path));
    }

    /**
     * @return void
     */
    public function testAllowlistedPlainPathStillWrites(): void
    {
        $path = 'general/store_information/name';

        $response = $this->toolsCall('system.config.set', ['path' => $path, 'value' => 'MCPSEC Audit Store']);

        $this->assertJsonRpcSuccess($response);
        self::assertSame('MCPSEC Audit Store', ConfigState::read($path));
    }

    /**
     * @param string $expected
     * @return void
     */
    private function assertAuditCode(string $expected): void
    {
        $audit = AuditLogReader::latest('system.config.set', $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for system.config.set.');
        self::assertSame($expected, $audit['error_code'] ?? null);
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
