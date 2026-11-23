<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\JobSchedulerInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\AuditLog\PiiRedactor;
use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\Mcp\Model\AuditLog\TextRedactor;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\Bulk\BulkExecutor;
use Magebit\Mcp\Model\Tool\Bulk\BulkSchemaFactory;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Tool\System\ConfigSet;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * A full 50-item `system.config.set` call through the real executor, adapter, PII redactor and audit logger.
 */
class ConfigSetAuditTest extends TestCase
{
    private const VALUE_BYTES = 80;

    /**
     * @return void
     */
    public function testFiftyItemCallKeepsEveryItemsDetailWithinTheSummaryBudget(): void
    {
        $items = [];
        for ($i = 0; $i < 50; $i++) {
            $items[] = [
                'path' => sprintf('general/store_information/custom_field_%02d', $i),
                'value' => $this->value('new', $i),
                'scope' => $i % 2 === 0 ? 'default' : 'websites',
            ] + ($i % 2 === 0 ? [] : ['scope_code' => 'base']);
        }

        $row = $this->callAndAudit($items, false);
        $encoded = $row[AuditEntryInterface::RESULT_SUMMARY_JSON] ?? null;

        self::assertIsString($encoded);
        self::assertLessThanOrEqual(AuditLogger::SUMMARY_BUDGET_BYTES, strlen($encoded));
        $summary = json_decode($encoded, true);
        self::assertIsArray($summary);
        self::assertSame(50, $summary['ok']);
        self::assertSame(50, $summary['item_count']);
        self::assertArrayNotHasKey('items_truncated', $summary);
        self::assertIsArray($summary['items']);
        self::assertCount(50, $summary['items']);
        self::assertSame([
            'index' => 49,
            'key' => 'general/store_information/custom_field_49',
            'status' => 'ok',
            'detail' => [
                'path' => 'general/store_information/custom_field_49',
                'scope' => 'websites',
                'scope_code' => 'base',
                'previous' => $this->value('old', 49),
                'value' => $this->value('new', 49),
            ],
        ], $summary['items'][49]);

        // Review H1: the arguments of a routine 50-item call are over the 4 KiB budget; they must still be stored as
        // valid JSON, or the JSON column refuses the whole row.
        $arguments = $row[AuditEntryInterface::ARGUMENTS_JSON] ?? null;
        self::assertIsString($arguments);
        self::assertLessThanOrEqual(AuditLogger::ARGUMENTS_BUDGET_BYTES, strlen($arguments));
        $envelope = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertTrue($envelope['__truncated__'] ?? null);
        self::assertGreaterThan(AuditLogger::ARGUMENTS_BUDGET_BYTES, $envelope['original_bytes'] ?? 0);
    }

    /**
     * Review M1: a path the sensitivity guard refuses never has its value in the audit row, even as plain text the
     * PII redactor would not recognise.
     *
     * @return void
     */
    public function testSensitivePathValueIsNotStored(): void
    {
        $row = $this->callAndAudit(
            [
                ['path' => 'carriers/ups/password', 'value' => 'S3cr3t-Pa55w0rd-xyz'],
                ['path' => 'general/store_information/custom_field_01', 'value' => 'plain value'],
            ],
            false,
            ['carriers/ups/password' => 'field_type_sensitive']
        );
        $encoded = $row[AuditEntryInterface::RESULT_SUMMARY_JSON] ?? null;

        self::assertIsString($encoded);
        self::assertStringNotContainsString('S3cr3t-Pa55w0rd-xyz', $encoded);
        $summary = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame([
            'index' => 0,
            'key' => 'carriers/ups/password',
            'status' => 'error',
            'detail' => [
                'path' => 'carriers/ups/password',
                'scope' => 'default',
                'scope_code' => null,
                'value' => '[sensitive]',
            ],
        ], $summary['items'][0] ?? null);
        self::assertSame('plain value', $summary['items'][1]['detail']['value'] ?? null);
    }

    /**
     * Config values can be personal data: the detail goes through the redactor with the rest of the summary.
     *
     * @return void
     */
    public function testItemDetailIsRedacted(): void
    {
        $row = $this->callAndAudit(
            [['path' => 'trans_email/ident_general/email', 'value' => 'owner@example.com']],
            true
        );
        $encoded = $row[AuditEntryInterface::RESULT_SUMMARY_JSON] ?? null;

        self::assertIsString($encoded);
        self::assertStringNotContainsString('owner@example.com', $encoded);
        self::assertStringNotContainsString('previous@example.com', $encoded);
        self::assertMatchesRegularExpression('/\[EMAIL:[0-9a-f]{16}\]/', $encoded);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param bool $dryRun
     * @param array<string, string> $sensitive Paths the sensitivity guard refuses, with the reason.
     * @return array<string, mixed> The inserted audit row.
     */
    private function callAndAudit(array $items, bool $dryRun, array $sensitive = []): array
    {
        $section = $this->createMock(Section::class);
        $section->method('getAttribute')->with('resource')->willReturn('Magento_Config::config_general');
        $writer = $this->createMock(ConfigPathWriter::class);
        $writer->method('sectionFor')->willReturn($section);
        $writer->method('currentValue')->willReturnCallback(
            fn (string $path): string => str_contains($path, 'email')
                ? 'previous@example.com'
                : $this->value('old', (int) substr($path, -2))
        );
        $guard = $this->createMock(SensitiveFieldGuard::class);
        $guard->method('rejectionFor')->willReturnCallback(
            static fn (string $path): ?string => $sensitive[$path] ?? null
        );
        $tool = new ConfigSet(
            $this->createMock(ConfigWritePolicy::class),
            $guard,
            $writer,
            $this->createMock(Manager::class)
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturn(true);
        $admin = $this->createMock(User::class);
        $provider = new AuthenticatedContextProvider();
        $provider->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $admin));
        $adapter = new BulkToolAdapter(
            $tool,
            $this->createMock(BulkSchemaFactory::class),
            new BulkExecutor($acl, $logger),
            $this->createMock(JobSchedulerInterface::class),
            $provider
        );

        $result = $adapter->execute(['items' => $items, 'dry_run' => $dryRun]);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');
        $redactor = new PiiRedactor(
            $deploymentConfig,
            [],
            new TextRedactor(new FingerprintStrategy($deploymentConfig), $logger, ['EMAIL'])
        );
        $row = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$row): int {
                $row = $data;
                return 1;
            }
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $auditLogger = new AuditLogger($resource, $this->createMock(DateTime::class), $redactor, $logger);

        $context = new AuditContext();
        $context->method = 'tools/call';
        $context->toolName = ConfigSet::TOOL_NAME;
        $context->arguments = ['items' => $items, 'dry_run' => $dryRun];
        $context->resultSummary = $result->getAuditSummary();
        $auditLogger->write($context);

        self::assertIsArray($row);
        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * A realistic ~80-byte config value.
     *
     * @param string $prefix
     * @param int $index
     * @return string
     */
    private function value(string $prefix, int $index): string
    {
        $text = sprintf('%s value %02d for the store information block, ', $prefix, $index);

        return str_pad($text, self::VALUE_BYTES, 'x');
    }
}
