<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Log;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Log\LogFileResolver;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Log\ListFiles;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

class ListFilesTest extends TestCase
{
    private string $dir;

    private ListFiles $tool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magebit-mcp-list-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/system.log', "warn\n");
        file_put_contents($this->dir . '/exception.log', "boom\n");
        file_put_contents($this->dir . '/env.php', "secret\n");

        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(AppDirectoryList::LOG)->willReturn($this->dir);

        $this->tool = new ListFiles(new LogFileResolver($directoryList, new FileDriver(), $this->allowAllFixtures()));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testMetadata(): void
    {
        self::assertSame('system.log.list', $this->tool->getName());
        self::assertSame('Magebit_Mcp::tool_system_log_list', $this->tool->getAclResource());
        self::assertSame(WriteMode::READ, $this->tool->getWriteMode());
        self::assertFalse($this->tool->getConfirmationRequired());
    }

    public function testSchemaTakesNoArguments(): void
    {
        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ], $this->tool->getInputSchema());
    }

    public function testExecuteListsLogFilesOnly(): void
    {
        $payload = $this->execute();

        self::assertSame(2, $payload['file_count']);
        self::assertSame(['exception.log', 'system.log'], array_column($payload['files'], 'file'));
        self::assertSame(5, $payload['files'][0]['size_bytes']);
        self::assertArrayHasKey('modified_at', $payload['files'][0]);
        self::assertFalse($payload['truncated']);
    }

    public function testExecuteReportsTruncationWhenTheDirectoryHoldsMoreFilesThanTheCap(): void
    {
        $reflection = new \ReflectionClass(LogFileResolver::class);
        $cap = $reflection->getConstant('MAX_LISTED_FILES');
        self::assertIsInt($cap);

        $allowed = [];
        for ($i = 0; $i <= $cap; $i++) {
            $name = sprintf('extra%04d.log', $i);
            file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, "x\n");
            $allowed[] = $name;
        }

        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(AppDirectoryList::LOG)->willReturn($this->dir);
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getAllowedLogFiles')->willReturn($allowed);
        $this->tool = new ListFiles(new LogFileResolver($directoryList, new FileDriver(), $config));

        $payload = $this->execute();

        self::assertTrue($payload['truncated']);
        self::assertSame($cap, $payload['file_count']);
    }

    public function testAuditSummaryRecordsOnlyTheCount(): void
    {
        $result = $this->tool->execute([]);

        self::assertSame(['files' => 2], $result->getAuditSummary());
    }

    public function testExecuteDescribesTheCommonLogFilesForTheClient(): void
    {
        self::assertStringContainsString('exception.log', $this->tool->getDescription());
    }

    /**
     * @phpstan-return array{
     *     files: list<array{file: string, size_bytes: int, modified_at: string}>,
     *     file_count: int,
     *     truncated: bool
     * }
     * @return array
     */
    private function execute(): array
    {
        $content = $this->tool->execute([])->getContent();
        self::assertCount(1, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /**
         * @phpstan-var array{files: list<array{file: string, size_bytes: int,
         *     modified_at: string}>, file_count: int, truncated: bool} $decoded
         */
        return $decoded;
    }
    /**
     * @param string $path
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }

    /**
     * @return ModuleConfig
     */
    private function allowAllFixtures(): ModuleConfig
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getAllowedLogFiles')->willReturn(['system.log', 'exception.log']);

        return $config;
    }
}
