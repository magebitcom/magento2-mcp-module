<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\FreshDefaultConfigReader;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Placeholder\Environment;
use Magento\Config\Model\Placeholder\PlaceholderFactory;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FreshDefaultConfigReaderTest extends TestCase
{
    private const PATH = 'magebit_mcp/general/allow_writes';
    private const ENV_NAME = 'CONFIG__DEFAULT__MAGEBIT_MCP__GENERAL__ALLOW_WRITES';

    /**
     * @phpstan-var DeploymentConfig&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private DeploymentConfig&MockObject $deploymentConfig;

    /**
     * @phpstan-var AdapterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdapterInterface&MockObject $connection;

    /**
     * @phpstan-var Initial&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Initial&MockObject $initial;

    /**
     * @var list<array{0: string, 1: mixed}> where() calls as [condition, value].
     */
    private array $where = [];

    private FreshDefaultConfigReader $reader;

    /**
     * @var array{0: bool, 1: mixed} Whether the test variable was set before the test, and its value.
     */
    private array $envBefore = [false, null];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:ignore Magento2.Security.Superglobal
        $this->envBefore = [array_key_exists(self::ENV_NAME, $_ENV), $_ENV[self::ENV_NAME] ?? null];
        // phpcs:ignore Magento2.Security.Superglobal
        unset($_ENV[self::ENV_NAME]);

        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(function (string $condition, $value = null) use ($select) {
            $this->where[] = [$condition, $value];
            return $select;
        });
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->initial = $this->createMock(Initial::class);

        // Magento's own checker and environment placeholder, so the variable name is built the way Magento does.
        $placeholders = $this->createMock(PlaceholderFactory::class);
        $placeholders->method('create')->willReturn(new Environment($this->deploymentConfig));
        $settingChecker = new SettingChecker(
            $this->deploymentConfig,
            $placeholders,
            $this->createMock(ScopeCodeResolver::class)
        );

        $this->reader = new FreshDefaultConfigReader(
            $settingChecker,
            $this->deploymentConfig,
            $resource,
            $this->initial
        );
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        // phpcs:disable Magento2.Security.Superglobal
        if ($this->envBefore[0]) {
            $_ENV[self::ENV_NAME] = $this->envBefore[1];
        } else {
            unset($_ENV[self::ENV_NAME]);
        }
        // phpcs:enable
    }

    /**
     * Follow-up B: `CONFIG__DEFAULT__<PATH>` overrides env.php, config.php and the database, as in Magento.
     *
     * @return void
     */
    public function testEnvironmentVariableWinsOverEverything(): void
    {
        // phpcs:ignore Magento2.Security.Superglobal
        $_ENV[self::ENV_NAME] = '0';
        $this->deploymentConfig->method('get')->willReturn('1');
        $this->connection->expects(self::never())->method('fetchRow');
        $this->initial->expects(self::never())->method('getData');

        self::assertSame('0', $this->reader->get(self::PATH));
        self::assertFalse($this->reader->isSetFlag(self::PATH));
    }

    /**
     * An env.php / config.php lock wins over the database.
     *
     * @return void
     */
    public function testDeploymentLockWins(): void
    {
        $this->deploymentConfig->method('get')->with('system/default/' . self::PATH)->willReturn(0);
        $this->connection->expects(self::never())->method('fetchRow');
        $this->initial->expects(self::never())->method('getData');

        self::assertSame('0', $this->reader->get(self::PATH));
        self::assertFalse($this->reader->isSetFlag(self::PATH));
    }

    /**
     * Without a lock the default-scope row is read from the database on every call, never from a cache.
     *
     * @return void
     */
    public function testDatabaseRowIsReadOnEveryCall(): void
    {
        $this->deploymentConfig->method('get')->willReturn(null);
        $this->connection->expects(self::exactly(2))->method('fetchRow')
            ->willReturnOnConsecutiveCalls(['value' => '1'], ['value' => '0']);
        $this->initial->expects(self::never())->method('getData');

        self::assertTrue($this->reader->isSetFlag(self::PATH));
        self::assertFalse($this->reader->isSetFlag(self::PATH));
        self::assertContains(['scope = ?', 'default'], $this->where);
        self::assertContains(['scope_id = ?', 0], $this->where);
        self::assertContains(['path = ?', self::PATH], $this->where);
    }

    /**
     * A row whose value is NULL still overrides the config.xml default.
     *
     * @return void
     */
    public function testNullDatabaseValueIsKept(): void
    {
        $this->deploymentConfig->method('get')->willReturn(null);
        $this->connection->method('fetchRow')->willReturn(['value' => null]);
        $this->initial->expects(self::never())->method('getData');

        self::assertNull($this->reader->get(self::PATH));
    }

    /**
     * Follow-up C: "Use system value" deletes the row; the value is then the shipped config.xml default, not the
     * merged config the process loaded at start (which still holds the deleted row).
     *
     * @return void
     */
    public function testDeletedRowFallsBackToTheShippedDefault(): void
    {
        $this->deploymentConfig->method('get')->willReturn(null);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->initial->expects(self::once())->method('getData')->with('default')
            ->willReturn(['magebit_mcp' => ['general' => ['allow_writes' => '0', 'enabled' => '1']]]);

        self::assertSame('0', $this->reader->get(self::PATH));
    }

    /**
     * @return void
     */
    public function testPathWithoutAShippedDefaultIsNull(): void
    {
        $this->deploymentConfig->method('get')->willReturn(null);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->initial->method('getData')->willReturn(['magebit_mcp' => ['general' => ['enabled' => '1']]]);

        self::assertNull($this->reader->get(self::PATH));
        self::assertNull($this->reader->get('magebit_mcp/general'));
    }
}
