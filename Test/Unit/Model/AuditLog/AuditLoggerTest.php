<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\AuditLog;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\AuditLog\PiiRedactor;
use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\Mcp\Model\AuditLog\TextRedactor;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;

    private AdapterInterface&MockObject $connection;

    private DateTime&MockObject $dateTime;

    private LoggerInterface&MockObject $logger;

    private PiiRedactor&MockObject $redactor;

    private AuditLogger $auditLogger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->redactor = $this->createMock(PiiRedactor::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $name): string => $name);
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 12:00:00');

        $this->auditLogger = new AuditLogger(
            $this->resourceConnection,
            $this->dateTime,
            $this->redactor,
            $this->logger
        );
    }

    /**
     * @return void
     */
    public function testResultSummaryIsRedactedNotOnlyArguments(): void
    {
        $this->redactor->expects(self::exactly(2))
            ->method('redact')
            ->willReturnArgument(0);
        $this->connection->expects(self::once())->method('insert');
        $this->logger->expects(self::never())->method('error');

        $this->auditLogger->write(
            $this->contextWithSummary(['sql' => "SELECT 1 WHERE email = 'jane@example.com'"])
        );
    }

    /**
     * Round-trip proof with a real redaction pipeline: a literal e-mail address inside the
     * SQL summary (what `db.query` writes) must never reach the inserted row, and the row
     * must instead carry the deterministic fingerprint the pattern pass produces.
     *
     * @return void
     */
    public function testResultSummaryEmailIsFingerprintedNotLeakedInInsertedRow(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');

        $textRedactor = new TextRedactor(
            new FingerprintStrategy($deploymentConfig),
            $this->logger,
            ['EMAIL']
        );
        $realRedactor = new PiiRedactor($deploymentConfig, [], $textRedactor);

        $auditLogger = new AuditLogger($this->resourceConnection, $this->dateTime, $realRedactor, $this->logger);

        $capturedRow = null;
        $this->connection->expects(self::once())->method('insert')
            ->with(
                self::anything(),
                self::callback(function (array $row) use (&$capturedRow): bool {
                    $capturedRow = $row;
                    return true;
                })
            );
        $this->logger->expects(self::never())->method('error');

        $auditLogger->write(
            $this->contextWithSummary(['sql' => "SELECT 1 WHERE email = 'jane@example.com'"])
        );

        self::assertIsArray($capturedRow);
        self::assertIsString($capturedRow[AuditEntryInterface::RESULT_SUMMARY_JSON]);
        self::assertStringNotContainsString('jane@example.com', $capturedRow[AuditEntryInterface::RESULT_SUMMARY_JSON]);
        self::assertMatchesRegularExpression(
            '/\[EMAIL:[0-9a-f]{16}\]/',
            $capturedRow[AuditEntryInterface::RESULT_SUMMARY_JSON]
        );
    }

    /**
     * @param array<int|string, mixed> $summary
     * @return AuditContext
     */
    private function contextWithSummary(array $summary): AuditContext
    {
        $context = new AuditContext();
        $context->method = 'tools/call';
        $context->toolName = 'db.query';
        $context->arguments = ['table' => 'customer_entity'];
        $context->resultSummary = $summary;

        return $context;
    }
}
