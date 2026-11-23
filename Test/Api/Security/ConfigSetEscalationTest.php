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

        $response = $this->setItems([['path' => $path, 'value' => $value]]);

        $envelope = $this->envelope($response, true);
        self::assertSame('FORBIDDEN', $envelope['results'][0]['error_code']);
        self::assertStringContainsString('is protected by', $this->message($envelope['results'][0]));
        $this->assertAuditStatus('error');
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

        $response = $this->setItems([['path' => $path, 'value' => '123456']]);

        $envelope = $this->envelope($response, true);
        self::assertSame('FORBIDDEN', $envelope['results'][0]['error_code']);
        self::assertStringContainsString(
            'cannot be written over MCP (field_type_sensitive',
            $this->message($envelope['results'][0])
        );
        $this->assertAuditStatus('error');
        self::assertSame($hadRow, ConfigState::hasRow($path));
        self::assertSame($before, ConfigState::read($path));
    }

    /**
     * @return void
     */
    public function testAllowlistedPlainPathStillWrites(): void
    {
        $path = 'general/store_information/name';

        $response = $this->setItems([['path' => $path, 'value' => 'MCPSEC Audit Store']]);

        $envelope = $this->envelope($response, false);
        self::assertSame('ok', $envelope['results'][0]['status']);
        self::assertSame('MCPSEC Audit Store', ConfigState::read($path));
    }

    /**
     * A refused item does not stop the batch: the protected row stays as it was, the allowed row is
     * saved, and the call as a whole is not an error because one item succeeded.
     *
     * @return void
     */
    public function testMixedBatchSavesTheAllowedItemAndRefusesTheProtectedOne(): void
    {
        $protected = 'magebit_mcp/general/allow_writes';
        $allowed = 'general/store_information/name';
        $before = ConfigState::read($protected);

        $response = $this->setItems([
            ['path' => $protected, 'value' => '0'],
            ['path' => $allowed, 'value' => 'MCPSEC Mixed Batch'],
        ]);

        $envelope = $this->envelope($response, false);
        self::assertSame('error', $envelope['results'][0]['status']);
        self::assertSame('FORBIDDEN', $envelope['results'][0]['error_code']);
        self::assertSame($protected, $envelope['results'][0]['key']);
        self::assertSame('ok', $envelope['results'][1]['status']);
        self::assertSame($allowed, $envelope['results'][1]['key']);
        self::assertStringStartsWith('previous: ', $this->message($envelope['results'][1]));
        self::assertSame(1, $envelope['summary']['ok']);
        self::assertSame(1, $envelope['summary']['failed']);
        self::assertSame($before, ConfigState::read($protected));
        self::assertSame('MCPSEC Mixed Batch', ConfigState::read($allowed));
    }

    /**
     * @return void
     */
    public function testDuplicateItemsRefuseTheWholeCallBeforeAnyWrite(): void
    {
        $path = 'general/store_information/name';
        $before = ConfigState::read($path);

        $response = $this->setItems([
            ['path' => $path, 'value' => 'MCPSEC First'],
            ['path' => $path, 'value' => 'MCPSEC Second'],
        ]);

        self::assertArrayHasKey('error', $response['body'] ?? []);
        self::assertSame($before, ConfigState::read($path));
    }

    /**
     * @param array $items
     * @phpstan-param list<array<string, mixed>> $items
     * @return array
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    private function setItems(array $items): array
    {
        return $this->toolsCall('system.config.set', ['items' => $items]);
    }

    /**
     * Decodes the bulk envelope out of a tool result and checks the call-level isError flag.
     *
     * @param array $response
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param bool $expectIsError
     * @return array
     * @phpstan-return array{summary: array<string, int>, results: list<array<string, mixed>>}
     */
    private function envelope(array $response, bool $expectIsError): array
    {
        $this->assertJsonRpcSuccess($response);
        $result = $response['body']['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame($expectIsError, $result['isError'] ?? null);
        $text = $result['content'][0]['text'] ?? null;
        self::assertIsString($text);
        $envelope = json_decode($text, true);
        self::assertIsArray($envelope);
        /** @var array{summary: array<string, int>, results: list<array<string, mixed>>} $envelope */

        return $envelope;
    }

    /**
     * @param array $row
     * @phpstan-param array<string, mixed> $row
     * @return string
     */
    private function message(array $row): string
    {
        $message = $row['message'] ?? null;
        self::assertIsString($message);

        return $message;
    }

    /**
     * @param string $expected
     * @return void
     */
    private function assertAuditStatus(string $expected): void
    {
        $audit = AuditLogReader::latest('system.config.set', $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for system.config.set.');
        self::assertSame($expected, $audit['response_status'] ?? null);
    }
}
