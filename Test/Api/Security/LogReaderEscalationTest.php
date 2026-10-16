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
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * The log tools only read allowlisted files from `var/log`, never anything else.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class LogReaderEscalationTest extends McpTestCase
{
    private const PROBE_FILE = 'mcpsec_probe.log';
    private const ROTATED_FILE = 'system.log.1';

    /** True when this test wrote `system.log.1`, so tearDown only removes its own file. */
    private ?bool $createdRotated = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->createdRotated = false;
        ConfigState::set('magebit_mcp/log_tools/allowed_files', "system.log\nexception.log\ndebug.log\ncron.log");
        parent::setUp();
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    protected function tearDown(): void
    {
        $dir = $this->logDir();
        if ($dir->isExist(self::PROBE_FILE)) {
            $dir->delete(self::PROBE_FILE);
        }
        if ($this->createdRotated === true && $dir->isExist(self::ROTATED_FILE)) {
            $dir->delete(self::ROTATED_FILE);
        }
        $this->createdRotated = false;
        ConfigState::restoreAll();
        parent::tearDown();
    }

    /**
     * @dataProvider forbiddenNames
     * @param string $file
     * @return void
     */
    public function testTailRefusesName(string $file): void
    {
        $response = $this->toolsCall('system.log.tail', ['file' => $file, 'lines' => 5]);

        $this->assertRefused($response, 'system.log.tail');
    }

    /**
     * @dataProvider forbiddenNames
     * @param string $file
     * @return void
     */
    public function testGrepRefusesName(string $file): void
    {
        $response = $this->toolsCall(
            'system.log.grep',
            ['file' => $file, 'contains' => 'key', 'max_matches' => 5]
        );

        $this->assertRefused($response, 'system.log.grep');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenNames(): array
    {
        return [
            'relative traversal' => ['../../app/etc/env.php'],
            'absolute path' => ['/var/www/html/app/etc/env.php'],
            'plain env.php' => ['env.php'],
            'dot file' => ['.htaccess'],
            'traversal after log name' => ['system.log/../env.php'],
            'null byte' => ["system\0.log"],
            'log off the allowlist' => ['magento.log'],
            'non-numeric suffix' => ['exception.log.abc'],
        ];
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    public function testOffAllowlistLogAnswersSameWhetherFileExists(): void
    {
        $this->logDir()->writeFile(self::PROBE_FILE, "mcpsec probe line\n");

        $existing = $this->toolsCall('system.log.tail', ['file' => self::PROBE_FILE]);
        $missing = $this->toolsCall('system.log.tail', ['file' => 'mcpsec_missing.log']);

        $this->assertJsonRpcError($existing, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        $this->assertJsonRpcError($missing, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        self::assertSame(
            str_replace('mcpsec_missing.log', 'X', $this->errorMessage($missing)),
            str_replace(self::PROBE_FILE, 'X', $this->errorMessage($existing))
        );
    }

    /**
     * @return void
     * @throws FileSystemException
     */
    public function testRotatedCopyOfAllowedLogIsAccepted(): void
    {
        $dir = $this->logDir();
        if ($dir->isExist(self::ROTATED_FILE)) {
            self::markTestSkipped('A real rotated system.log.1 is present; not touching it.');
        }
        $dir->writeFile(self::ROTATED_FILE, "mcpsec-marker\n");
        $this->createdRotated = true;

        $response = $this->toolsCall('system.log.tail', ['file' => self::ROTATED_FILE]);

        $this->assertJsonRpcSuccess($response);
        self::assertTrue(ToolPayload::contains(ToolPayload::decode($response), 'mcpsec-marker'));
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     * @param array $response
     * @param string $toolName
     * @return void
     */
    private function assertRefused(array $response, string $toolName): void
    {
        $this->assertJsonRpcError($response, ErrorCode::TOOL_EXECUTION_FAILED->value, 200);
        $encoded = (string) json_encode($response['body']);
        // Markers from app/etc/env.php: the file content must never come back.
        self::assertStringNotContainsString("'crypt'", $encoded);
        self::assertStringNotContainsString("'db'", $encoded);
        $audit = AuditLogReader::latest($toolName, $this->tokenRowId);
        self::assertNotNull($audit, 'Expected an audit row for ' . $toolName);
        self::assertSame('-32011', $audit['error_code'] ?? null);
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

    /**
     * @return WriteInterface
     * @throws FileSystemException
     */
    private function logDir(): WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::LOG);
    }
}
