<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Log;

use Magebit\Mcp\Model\Log\LogFileResolver;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is the only thing standing between a token holder and an
 * arbitrary-file read (`app/etc/env.php` holds the DB credentials and crypt
 * key), so every rejection path is asserted explicitly.
 */
class LogFileResolverTest extends TestCase
{
    private string $sandbox;

    private string $logDir;

    private string $outsideDir;

    /**
     * Sandbox fixture names, so these cases exercise containment not the allowlist.
     *
     * @var list<string>
     */
    private const SANDBOX_ALLOWLIST = [
        'system.log',
        'exception.log',
        'escape.log',
        'leak.log',
        'directory.log',
        'nested.log',
        'nosuchfile.log',
    ];

    private LogFileResolver $resolver;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magebit-mcp-log-' . bin2hex(random_bytes(6));
        $this->sandbox = $base;
        // A sibling whose name shares the log directory's prefix — an
        // unterminated str_starts_with() check would accept paths inside it.
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'log';
        $this->outsideDir = $base . DIRECTORY_SEPARATOR . 'logsecret';

        mkdir($this->logDir, 0777, true);
        mkdir($this->outsideDir, 0777, true);

        file_put_contents($this->logDir . '/system.log', "one\ntwo\n");
        file_put_contents($this->logDir . '/exception.log', "boom\n");
        file_put_contents($this->logDir . '/system.log.1', "rotated\n");
        file_put_contents($this->logDir . '/env.php', "<?php return ['db' => 'secret'];\n");
        file_put_contents($this->outsideDir . '/secret.log', "credentials\n");

        $this->resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(self::SANDBOX_ALLOWLIST)
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);
    }

    public function testResolvesAPlainLogFile(): void
    {
        self::assertSame(
            realpath($this->logDir . '/system.log'),
            $this->resolver->resolve('system.log')
        );
    }

    public function testResolvesExceptionLog(): void
    {
        self::assertSame(
            realpath($this->logDir . '/exception.log'),
            $this->resolver->resolve('exception.log')
        );
    }

    public function testResolvesRotatedLogFile(): void
    {
        self::assertSame(
            realpath($this->logDir . '/system.log.1'),
            $this->resolver->resolve('system.log.1')
        );
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(
            realpath($this->logDir . '/system.log'),
            $this->resolver->resolve("  system.log\t")
        );
    }

    /**
     * @param string $file
     * @return void
     * @dataProvider rejectedNameProvider
     */
    public function testRejectsUnsafeName(string $file): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver->resolve($file);
    }

    /**
     * The syntax gate is also the public source of a display name, so it has to
     * reject on its own — a caller that only normalizes must still be safe.
     *
     * @param string $file
     * @return void
     * @dataProvider rejectedNameProvider
     */
    public function testNormalizeRejectsEverythingResolveRejects(string $file): void
    {
        if ($file === 'nosuchfile.log') {
            // Existence is a filesystem question, not a syntax one.
            self::assertSame('nosuchfile.log', $this->resolver->normalize($file));
            return;
        }

        $this->expectException(LocalizedException::class);
        $this->resolver->normalize($file);
    }

    public function testNormalizeReturnsTheTrimmedName(): void
    {
        self::assertSame('system.log', $this->resolver->normalize("  system.log \n"));
        self::assertSame('system.log.1', $this->resolver->normalize('system.log.1'));
    }

    public function testNormalizeTouchesNoFilesystem(): void
    {
        // A resolver pointed at a directory that does not exist must still hand
        // back a name: nothing here may depend on the log directory resolving.
        $resolver = new LogFileResolver(
            $this->directoryList($this->sandbox . DIRECTORY_SEPARATOR . 'nope'),
            new FileDriver(),
            $this->moduleConfig(self::SANDBOX_ALLOWLIST)
        );

        self::assertSame('exception.log', $resolver->normalize('exception.log'));
    }

    /**
     * @phpstan-return array<string, array{string}>
     * @return array[]
     */
    public static function rejectedNameProvider(): array
    {
        return [
            'parent traversal to env.php' => ['../../app/etc/env.php'],
            'relative traversal' => ['../secret.log'],
            'absolute unix path' => ['/etc/passwd'],
            'absolute path to env.php' => ['/var/www/demo/app/etc/env.php'],
            'traversal after a valid name' => ['foo.log/../../../etc/passwd'],
            'bare parent' => ['..'],
            'single dot' => ['.'],
            'subdirectory' => ['sub/system.log'],
            'trailing separator' => ['system.log/'],
            'windows separator' => ['sub\\system.log'],
            'windows drive' => ['C:\\windows\\system.log'],
            'php file' => ['env.php'],
            'wrong extension' => ['system.log.txt'],
            'no extension' => ['system'],
            'uppercase extension' => ['SYSTEM.LOG'],
            'non numeric rotation' => ['system.log.bak'],
            'gzipped rotation' => ['system.log.gz'],
            'empty string' => [''],
            'whitespace only' => ["   \t"],
            'null byte truncation' => ["system.log\0.txt"],
            'null byte then traversal' => ["system.log\0/../../app/etc/env.php"],
            'url encoded null byte' => ['system.log%00.txt'],
            'newline injection' => ["system.log\ninjected"],
            'hidden dotfile' => ['.hidden.log'],
            'missing file' => ['nosuchfile.log'],
            'glob wildcard' => ['*.log'],
        ];
    }

    /**
     * @param mixed $file
     * @return void
     * @dataProvider nonStringProvider
     */
    public function testRejectsNonStringName(mixed $file): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver->resolve($file);
    }

    /**
     * @phpstan-return array<string, array{mixed}>
     * @return array[]
     */
    public static function nonStringProvider(): array
    {
        return [
            'null' => [null],
            'integer' => [42],
            'float' => [1.5],
            'true' => [true],
            'false' => [false],
            'array' => [['system.log']],
            'object' => [new \stdClass()],
        ];
    }

    public function testRejectsAnOverlongName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver->resolve(str_repeat('a', 300) . '.log');
    }

    public function testRejectsASymlinkResolvingIntoASiblingSharingTheLogDirectoryPrefix(): void
    {
        // The argument is a legal basename, so only the post-realpath() check can
        // catch it: realpath() lands in `<sandbox>/logsecret/…`, which a
        // str_starts_with() against the unterminated `<sandbox>/log` accepts.
        self::assertTrue(
            symlink($this->outsideDir . '/secret.log', $this->logDir . '/escape.log'),
            'Test needs symlink support.'
        );

        $this->expectException(LocalizedException::class);
        $this->resolver->resolve('escape.log');
    }

    public function testRejectsASymlinkPointingAtAnUnrelatedDirectory(): void
    {
        $unrelated = $this->sandbox . DIRECTORY_SEPARATOR . 'elsewhere';
        mkdir($unrelated);
        file_put_contents($unrelated . '/env.log', "crypt key\n");
        self::assertTrue(
            symlink($unrelated . '/env.log', $this->logDir . '/leak.log'),
            'Test needs symlink support.'
        );

        $this->expectException(LocalizedException::class);
        $this->resolver->resolve('leak.log');
    }

    public function testRejectsADirectoryNamedLikeALogFile(): void
    {
        mkdir($this->logDir . '/directory.log');

        $this->expectException(LocalizedException::class);
        $this->resolver->resolve('directory.log');
    }

    public function testRejectsWhenTheLogDirectoryDoesNotExist(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->sandbox . DIRECTORY_SEPARATOR . 'nope'),
            new FileDriver(),
            $this->moduleConfig(self::SANDBOX_ALLOWLIST)
        );

        $this->expectException(LocalizedException::class);
        $resolver->resolve('system.log');
    }

    /**
     * @param string $file
     * @return void
     * @dataProvider leakProvider
     */
    public function testRejectionMessagesNeverDiscloseTheFilesystemLayout(string $file): void
    {
        try {
            $this->resolver->resolve($file);
            self::fail(sprintf('Expected "%s" to be rejected.', addcslashes($file, "\0..\37")));
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
            self::assertStringNotContainsString($this->sandbox, $message);
            self::assertStringNotContainsString($this->logDir, $message);
            self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'log', $message);
        }
    }

    /**
     * @phpstan-return array<string, array{string}>
     * @return array[]
     */
    public static function leakProvider(): array
    {
        return [
            'traversal' => ['../../app/etc/env.php'],
            'absolute' => ['/etc/passwd'],
            'wrong extension' => ['env.php'],
            'missing file' => ['nosuchfile.log'],
            'empty' => [''],
        ];
    }

    public function testListFilesReturnsOnlyLogFilesSortedByName(): void
    {
        mkdir($this->logDir . '/subdir');
        file_put_contents($this->logDir . '/subdir/nested.log', "nested\n");

        $listing = $this->resolver->listFiles();
        $names = array_column($listing['files'], 'file');

        self::assertSame(['exception.log', 'system.log', 'system.log.1'], $names);
        self::assertNotContains('env.php', $names);
        self::assertNotContains('subdir', $names);
        self::assertNotContains('nested.log', $names);
        self::assertFalse($listing['truncated']);
    }

    public function testListFilesReportsSizeAndModificationTime(): void
    {
        $files = [];
        foreach ($this->resolver->listFiles()['files'] as $entry) {
            $files[$entry['file']] = $entry;
        }

        self::assertSame(8, $files['system.log']['size_bytes']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $files['system.log']['modified_at']
        );
    }

    public function testListFilesSkipsSymlinksPointingOutsideTheLogDirectory(): void
    {
        self::assertTrue(
            symlink($this->outsideDir . '/secret.log', $this->logDir . '/escape.log'),
            'Test needs symlink support.'
        );

        $names = array_column($this->resolver->listFiles()['files'], 'file');

        self::assertNotContains('escape.log', $names);
    }

    public function testListFilesOnAMissingDirectoryReturnsNothing(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->sandbox . DIRECTORY_SEPARATOR . 'nope'),
            new FileDriver(),
            $this->moduleConfig(self::SANDBOX_ALLOWLIST)
        );

        self::assertSame(['files' => [], 'truncated' => false], $resolver->listFiles());
    }

    public function testListFilesReportsTruncationWhenTheDirectoryHoldsMoreFilesThanTheCap(): void
    {
        $reflection = new \ReflectionClass(LogFileResolver::class);
        $cap = $reflection->getConstant('MAX_LISTED_FILES');
        self::assertIsInt($cap);

        $allowed = [];
        for ($i = 0; $i <= $cap; $i++) {
            $name = sprintf('extra%04d.log', $i);
            file_put_contents($this->logDir . DIRECTORY_SEPARATOR . $name, "x\n");
            $allowed[] = $name;
        }

        $listing = (new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig($allowed)
        ))->listFiles();

        self::assertTrue($listing['truncated']);
        self::assertCount($cap, $listing['files']);
    }

    public function testListFilesReportsNoTruncationWhenUnderTheCap(): void
    {
        $listing = $this->resolver->listFiles();

        self::assertFalse($listing['truncated']);
    }

    public function testRefusesAFileThatIsNotOnTheAllowlist(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(['system.log'])
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/not on the list of log files/');

        $resolver->resolve('exception.log');
    }

    public function testAnEmptyAllowlistRefusesEveryFile(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig([])
        );

        $this->expectException(LocalizedException::class);

        $resolver->resolve('system.log');
    }

    public function testAllowlistRefusalDoesNotRevealWhetherTheFileExists(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(['system.log'])
        );

        $present = null;
        $absent = null;
        try {
            $resolver->resolve('exception.log');
        } catch (LocalizedException $e) {
            $present = $e->getMessage();
        }
        try {
            $resolver->resolve('nosuchfile.log');
        } catch (LocalizedException $e) {
            $absent = $e->getMessage();
        }

        self::assertNotNull($present);
        self::assertSame(
            str_replace('exception.log', 'X', (string) $present),
            str_replace('nosuchfile.log', 'X', (string) $absent)
        );
    }

    public function testAllowingABaseNameAlsoAllowsItsRotatedFiles(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(['system.log'])
        );

        self::assertSame(
            $this->logDir . DIRECTORY_SEPARATOR . 'system.log.1',
            $resolver->resolve('system.log.1')
        );
    }

    public function testRotationToleranceDoesNotLeakAcrossDifferentFiles(): void
    {
        file_put_contents($this->logDir . '/payments.log.2', "card\n");
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(['system.log'])
        );

        $this->expectException(LocalizedException::class);

        $resolver->resolve('payments.log.2');
    }

    public function testListFilesShowsOnlyAllowedFiles(): void
    {
        $resolver = new LogFileResolver(
            $this->directoryList($this->logDir),
            new FileDriver(),
            $this->moduleConfig(['system.log'])
        );

        $names = array_column($resolver->listFiles()['files'], 'file');

        self::assertSame(['system.log', 'system.log.1'], $names);
        self::assertNotContains('exception.log', $names);
    }

    /**
     * @param list<string> $allowed
     * @return ModuleConfig
     */
    private function moduleConfig(array $allowed): ModuleConfig
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getAllowedLogFiles')->willReturn($allowed);

        return $config;
    }

    /**
     * @param string $path
     * @return DirectoryList
     */
    private function directoryList(string $path): DirectoryList
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')
            ->with(AppDirectoryList::LOG)
            ->willReturn($path);

        return $directoryList;
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
}
