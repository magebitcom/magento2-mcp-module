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
     * Per-item detail gets the summary's own 32 KiB budget; it is not cut at the 4 KiB arguments budget.
     *
     * @return void
     */
    public function testSummaryItemsUseTheSummaryBudgetNotTheArgumentsBudget(): void
    {
        $summary = ['total' => 40, 'items' => $this->entries(40, 200)];
        $encoded = $this->writeAndCaptureSummary($summary);

        self::assertGreaterThan(4096, strlen($encoded));
        self::assertLessThanOrEqual(AuditLogger::SUMMARY_BUDGET_BYTES, strlen($encoded));
        self::assertSame($summary, json_decode($encoded, true));
    }

    /**
     * Over budget: whole leading entries are kept, the rest are counted in `items_truncated`, and the JSON stays valid.
     *
     * @return void
     */
    public function testOverBudgetItemsKeepWholeLeadingEntriesAndCountTheRest(): void
    {
        $summary = ['total' => 100, 'ok' => 100, 'items' => $this->entries(100, 500)];
        $encoded = $this->writeAndCaptureSummary($summary);

        self::assertLessThanOrEqual(AuditLogger::SUMMARY_BUDGET_BYTES, strlen($encoded));
        self::assertStringNotContainsString('__truncated__', $encoded);
        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertSame(100, $decoded['total']);
        self::assertIsArray($decoded['items']);
        $kept = count($decoded['items']);
        self::assertGreaterThan(50, $kept);
        self::assertSame(array_slice($summary['items'], 0, $kept), $decoded['items']);
        self::assertSame(100 - $kept, $decoded['items_truncated']);
        // Greedy: one more entry would not have fitted.
        $oneMore = $decoded;
        $oneMore['items'][] = $summary['items'][$kept];
        $oneMore['items_truncated'] = 100 - $kept - 1;
        self::assertGreaterThan(
            AuditLogger::SUMMARY_BUDGET_BYTES,
            strlen((string) json_encode($oneMore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * Entries the collector already dropped are added to the count, not replaced by it.
     *
     * @return void
     */
    public function testCollectorTruncationCountIsAddedTo(): void
    {
        $summary = ['items' => $this->entries(100, 500), 'items_truncated' => 7];
        $decoded = json_decode($this->writeAndCaptureSummary($summary), true);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['items']);
        self::assertSame(107 - count($decoded['items']), $decoded['items_truncated']);
    }

    /**
     * A summary without `items` that is over budget becomes a valid JSON envelope (review H1: the columns are JSON
     * columns, so a byte-cut string would make the INSERT fail and lose the row).
     *
     * @return void
     */
    public function testSummaryWithoutItemsOverBudgetBecomesAValidEnvelope(): void
    {
        $summary = ['blob' => str_repeat('x', 40000)];
        $encoded = $this->writeAndCaptureSummary($summary);

        self::assertLessThanOrEqual(AuditLogger::SUMMARY_BUDGET_BYTES, strlen($encoded));
        $original = (string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = $this->assertEnvelope($encoded, $original, AuditLogger::SUMMARY_BUDGET_BYTES);
        // About half the budget is kept for the head.
        self::assertGreaterThan(16000, strlen($envelope['head']));
    }

    /**
     * @return void
     */
    public function testArgumentsOverBudgetBecomeAValidEnvelopeWithinFourKib(): void
    {
        $arguments = ['items' => $this->entries(40, 200)];
        $encoded = $this->writeAndCaptureArguments($arguments);

        $original = (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertEnvelope($encoded, $original, AuditLogger::ARGUMENTS_BUDGET_BYTES);
    }

    /**
     * Worst cases for the envelope: multibyte text (the cut must not split a character) and quote- or
     * backslash-heavy text (escaping doubles the head).
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function overBudgetPayloadProvider(): array
    {
        $cases = [];
        $payloads = [
            'ascii' => str_repeat('abcdefgh', 6000),
            'multibyte' => str_repeat('Rīga – €uro 😀 ', 3000),
            'quotes and backslashes' => str_repeat('"\\"', 12000),
            'line terminators' => str_repeat("a\u{2028}b\n\t", 6000),
        ];
        foreach ($payloads as $name => $payload) {
            $cases['arguments, ' . $name] = ['arguments', AuditLogger::ARGUMENTS_BUDGET_BYTES, $payload];
            $cases['summary, ' . $name] = ['summary', AuditLogger::SUMMARY_BUDGET_BYTES, $payload];
        }
        return $cases;
    }

    /**
     * @dataProvider overBudgetPayloadProvider
     * @param string $column
     * @param int $budget
     * @param string $payload
     * @return void
     */
    public function testOverBudgetPayloadIsValidJsonWithinTheBudget(string $column, int $budget, string $payload): void
    {
        $data = ['text' => $payload, 'more' => [$payload]];
        $encoded = $column === 'arguments'
            ? $this->writeAndCaptureArguments($data)
            : $this->writeAndCaptureSummary($data);

        $original = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertEnvelope($encoded, $original, $budget);
        // The head uses the room: at least half the budget, whatever the escaping.
        self::assertGreaterThanOrEqual(intdiv($budget, 2), strlen($encoded));
    }

    /**
     * @param string $encoded
     * @param string $original
     * @param int $budget
     * @return array{__truncated__: true, original_bytes: int, head: string}
     */
    private function assertEnvelope(string $encoded, string $original, int $budget): array
    {
        self::assertLessThanOrEqual($budget, strlen($encoded));
        $envelope = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertSame(['__truncated__', 'original_bytes', 'head'], array_keys($envelope));
        self::assertTrue($envelope['__truncated__']);
        self::assertSame(strlen($original), $envelope['original_bytes']);
        self::assertIsString($envelope['head']);
        self::assertNotSame('', $envelope['head']);
        self::assertStringStartsWith($envelope['head'], $original);
        self::assertTrue(mb_check_encoding($envelope['head'], 'UTF-8'));
        /** @var array{__truncated__: true, original_bytes: int, head: string} $envelope */
        return $envelope;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     */
    private function writeAndCaptureArguments(array $arguments): string
    {
        $this->redactor->method('redact')->willReturnArgument(0);
        $row = null;
        $this->connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$row): int {
                $row = $data;
                return 1;
            }
        );
        $this->logger->expects(self::never())->method('error');
        $context = $this->contextWithSummary(['ok' => 1]);
        $context->arguments = $arguments;

        $this->auditLogger->write($context);

        self::assertIsArray($row);
        $encoded = $row[AuditEntryInterface::ARGUMENTS_JSON];
        self::assertIsString($encoded);
        return $encoded;
    }

    /**
     * The item detail goes through the same PII redactor as the rest of the summary, before it is fitted.
     *
     * @return void
     */
    public function testItemDetailIsRedactedLikeTheRestOfTheSummary(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');
        $realRedactor = new PiiRedactor(
            $deploymentConfig,
            [],
            new TextRedactor(new FingerprintStrategy($deploymentConfig), $this->logger, ['EMAIL'])
        );
        $auditLogger = new AuditLogger($this->resourceConnection, $this->dateTime, $realRedactor, $this->logger);
        $row = null;
        $this->connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$row): int {
                $row = $data;
                return 1;
            }
        );

        $auditLogger->write($this->contextWithSummary(['items' => [[
            'index' => 0,
            'key' => 'trans_email/ident_general/email',
            'status' => 'ok',
            'detail' => ['previous' => 'owner@example.com', 'value' => 'shop@example.com', 'api_token' => 'abc'],
        ]]]));

        self::assertIsArray($row);
        $encoded = $row[AuditEntryInterface::RESULT_SUMMARY_JSON];
        self::assertIsString($encoded);
        self::assertStringNotContainsString('owner@example.com', $encoded);
        self::assertStringNotContainsString('shop@example.com', $encoded);
        self::assertStringNotContainsString('"abc"', $encoded);
        self::assertMatchesRegularExpression('/\[EMAIL:[0-9a-f]{16}\]/', $encoded);
    }

    /**
     * @param array<string, mixed> $summary
     * @return string
     */
    private function writeAndCaptureSummary(array $summary): string
    {
        $this->redactor->method('redact')->willReturnArgument(0);
        $row = null;
        $this->connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$row): int {
                $row = $data;
                return 1;
            }
        );
        $this->logger->expects(self::never())->method('error');

        $this->auditLogger->write($this->contextWithSummary($summary));

        self::assertIsArray($row);
        $encoded = $row[AuditEntryInterface::RESULT_SUMMARY_JSON];
        self::assertIsString($encoded);
        return $encoded;
    }

    /**
     * @param int $count
     * @param int $valueBytes
     * @return list<array{index: int, key: string, status: string, detail: array<string, string>}>
     */
    private function entries(int $count, int $valueBytes): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = [
                'index' => $i,
                'key' => 'section/group/field_' . $i,
                'status' => 'ok',
                'detail' => ['value' => str_pad((string) $i, $valueBytes, 'v')],
            ];
        }
        return $entries;
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
