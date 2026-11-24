<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Api\BulkContextualAclAwareInterface;
use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\BulkToolLifecycleInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\BulkExecutor;
use Magebit\Mcp\Model\Tool\Bulk\ItemAuditCollector;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface BulkExecutorTestAclTool extends BulkToolInterface, BulkContextualAclAwareInterface
{
}

interface BulkExecutorTestLifecycleTool extends BulkToolInterface, BulkToolLifecycleInterface
{
}

interface BulkExecutorTestAuditTool extends BulkToolInterface, BulkItemAuditInterface
{
}

interface BulkExecutorTestAuditAclTool extends BulkExecutorTestAuditTool, BulkContextualAclAwareInterface
{
}

class BulkExecutorTest extends TestCase
{
    /** @var AclChecker&MockObject */
    private AclChecker $aclChecker;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var User&MockObject */
    private User $admin;
    private BulkExecutor $executor;

    protected function setUp(): void
    {
        $this->aclChecker = $this->createMock(AclChecker::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->admin = $this->createMock(User::class);
        $this->executor = new BulkExecutor($this->aclChecker, $this->logger);
    }

    /**
     * @template T of BulkToolInterface
     * @param class-string<T> $class
     * @return T&MockObject
     */
    private function tool(string $class): BulkToolInterface
    {
        $tool = $this->createMock($class);
        $tool->method('getName')->willReturn('test.bulk');
        $tool->method('getItemKeyField')->willReturn('sku');
        return $tool;
    }

    public function testMixedBatchSavesValidItems(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willReturnCallback(
            static function (array $item): ItemResult {
                if ($item['sku'] === 'B') {
                    throw new LocalizedException(new Phrase('B is broken'));
                }
                return ItemResult::ok(1);
            }
        );
        $result = $this->executor->run($tool, [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']], false, $this->admin);

        $rows = $result->rows();
        self::assertSame(['ok', 'error', 'ok'], array_column($rows, 'status'));
        self::assertSame(BulkErrorCode::ITEM_FAILED, $rows[1]['error_code']);
        self::assertSame('B is broken', $rows[1]['message']);
        self::assertSame('B', $rows[1]['key']);
        self::assertSame(['total' => 3, 'ok' => 2, 'failed' => 1, 'skipped' => 0], $result->summary());
    }

    public function testNonLocalizedThrowableIsGeneric(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willThrowException(new RuntimeException('SQLSTATE[23000] secret'));
        $this->logger->expects(self::once())->method('error');

        $rows = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin)->rows();

        self::assertSame(BulkErrorCode::ITEM_FAILED, $rows[0]['error_code']);
        self::assertSame('Item failed.', $rows[0]['message']);
    }

    public function testForbiddenExceptionKeepsMessage(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willThrowException(new ForbiddenException('Scope not allowed.'));

        $rows = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin)->rows();

        self::assertSame(BulkErrorCode::FORBIDDEN, $rows[0]['error_code']);
        self::assertSame('Scope not allowed.', $rows[0]['message']);
    }

    public function testItemAclDenied(): void
    {
        $tool = $this->tool(BulkExecutorTestAclTool::class);
        $tool->method('getItemAclResources')->willReturnCallback(
            static fn (array $item): array => [$item['sku'] === 'B' ? 'Denied::res' : 'Ok::res']
        );
        $this->aclChecker->method('isAllowed')->willReturnCallback(
            static fn (User $u, string $r): bool => $r === 'Ok::res'
        );
        $processed = [];
        $tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );

        $rows = $this->executor->run($tool, [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']], false, $this->admin)
            ->rows();

        self::assertSame(['ok', 'error', 'ok'], array_column($rows, 'status'));
        self::assertSame(BulkErrorCode::FORBIDDEN, $rows[1]['error_code']);
        self::assertSame(
            'Your admin role does not permit the Magento section this item targets.',
            $rows[1]['message']
        );
        self::assertSame(['A', 'C'], $processed);
    }

    public function testEmptyItemAclResourceIsDenied(): void
    {
        $tool = $this->tool(BulkExecutorTestAclTool::class);
        $tool->method('getItemAclResources')->willReturn(['']);
        $tool->expects(self::never())->method('processItem');
        $this->logger->expects(self::once())->method('error');

        $rows = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin)->rows();

        self::assertSame(BulkErrorCode::FORBIDDEN, $rows[0]['error_code']);
        self::assertStringContainsString('could not be determined', (string)$rows[0]['message']);
    }

    public function testNonStringAndThrowingAclResolutionIsDenied(): void
    {
        $tool = $this->tool(BulkExecutorTestAclTool::class);
        $tool->method('getItemAclResources')->willReturnCallback(
            static function (array $item): array {
                if ($item['sku'] === 'A') {
                    return [42];
                }
                throw new RuntimeException('boom');
            }
        );
        $tool->expects(self::never())->method('processItem');

        $rows = $this->executor->run($tool, [['sku' => 'A'], ['sku' => 'B']], false, $this->admin)->rows();

        self::assertSame([BulkErrorCode::FORBIDDEN, BulkErrorCode::FORBIDDEN], array_column($rows, 'error_code'));
    }

    public function testBeforeBatchThrowsRunsNothing(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('beforeBatch')->willThrowException(new LocalizedException(new Phrase('no prep')));
        $tool->expects(self::never())->method('processItem');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no prep');
        $this->executor->run($tool, [['sku' => 'A']], false, $this->admin);
    }

    public function testAfterBatchThrowsKeepsResults(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->method('afterBatch')->willThrowException(new RuntimeException('reindex failed'));
        $this->logger->expects(self::once())->method('error');

        $result = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin);

        self::assertCount(1, $result->rows());
        $envelope = $result->toEnvelope(false);
        self::assertArrayHasKey('notice', $envelope);
        self::assertStringContainsString('follow-up step after the batch failed', $envelope['notice'] ?? '');
    }

    /**
     * Review lap 1 #5: a job refreshes its heartbeat right before afterBatch.
     */
    public function testBeforeAfterBatchCallbackRunsRightBeforeAfterBatch(): void
    {
        $calls = [];
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('processItem')->willReturnCallback(static function () use (&$calls): ItemResult {
            $calls[] = 'item';
            return ItemResult::ok();
        });
        $tool->expects(self::once())->method('afterBatch')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'afterBatch';
            }
        );

        $this->executor->run(
            $tool,
            [['sku' => 'A'], ['sku' => 'B']],
            false,
            $this->admin,
            0,
            null,
            null,
            static function () use (&$calls): bool {
                $calls[] = 'beforeAfterBatch';
                return true;
            }
        );

        self::assertSame(['item', 'item', 'beforeAfterBatch', 'afterBatch'], $calls);
    }

    /**
     * False means the run lost the job; afterBatch belongs to whichever run owns it now.
     */
    public function testBeforeAfterBatchFalseSkipsAfterBatch(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->expects(self::never())->method('afterBatch');

        $result = $this->executor->run(
            $tool,
            [['sku' => 'A']],
            false,
            $this->admin,
            0,
            null,
            null,
            static fn (): bool => false
        );

        self::assertCount(1, $result->rows());
        self::assertNull($result->getNotice());
    }

    public function testAfterBatchNoticeIsReadable(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->method('afterBatch')->willThrowException(new RuntimeException('reindex failed'));

        $result = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin);

        self::assertStringContainsString('follow-up step after the batch failed', (string) $result->getNotice());
    }

    public function testLifecycleRunsOnceAroundLoop(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->expects(self::once())->method('beforeBatch')->with([['sku' => 'A'], ['sku' => 'B']], true);
        $tool->expects(self::once())->method('afterBatch')->with(self::isInstanceOf(BatchResult::class), true);

        $this->executor->run($tool, [['sku' => 'A'], ['sku' => 'B']], true, $this->admin);
    }

    public function testIndexIsPositionNotClientKey(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());

        $rows = $this->executor->run($tool, [5 => ['sku' => 'a'], 9 => ['sku' => 'b']], false, $this->admin)->rows();

        self::assertSame([0, 1], array_column($rows, 'index'));
        self::assertSame(['a', 'b'], array_column($rows, 'key'));
    }

    public function testKeyCastAndNull(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());

        $rows = $this->executor->run(
            $tool,
            [['sku' => 12], ['sku' => ['x']], ['other' => 1], ['sku' => null], ['sku' => true]],
            false,
            $this->admin
        )->rows();

        self::assertSame(['12', null, null, null, '1'], array_column($rows, 'key'));
    }

    public function testStartIndexAndStop(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $processed = [];
        $tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );
        $seen = [];
        $stopAfter = false;
        $result = $this->executor->run(
            $tool,
            [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c'], ['sku' => 'd']],
            false,
            $this->admin,
            2,
            static function (int $index, array $row) use (&$seen, &$stopAfter): void {
                $seen[] = [$index, $row['index']];
                $stopAfter = true;
            },
            static function () use (&$stopAfter): bool {
                return $stopAfter;
            }
        );

        self::assertSame(['c'], $processed);
        self::assertSame([2], array_column($result->rows(), 'index'));
        self::assertSame([[2, 2]], $seen);
    }

    public function testDryRunPassedThrough(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->expects(self::once())->method('processItem')->with(['sku' => 'A'], true)
            ->willReturn(ItemResult::ok());

        $this->executor->run($tool, [['sku' => 'A']], true, $this->admin);
    }

    public function testAuditDetailIsCollectedPerItemWithTheItemsResult(): void
    {
        $tool = $this->tool(BulkExecutorTestAuditTool::class);
        $tool->method('processItem')->willReturnCallback(
            static fn (array $item): ItemResult => $item['sku'] === 'B'
                ? ItemResult::error(BulkErrorCode::VALIDATION_FAILED, 'bad')
                : ItemResult::ok()
        );
        $seen = [];
        $tool->expects(self::exactly(3))->method('getItemAuditDetail')->willReturnCallback(
            static function (array $item, ItemResult $result, bool $dryRun) use (&$seen): ?array {
                $seen[] = [$item['sku'], $result->getStatus(), $dryRun];
                return $item['sku'] === 'C' ? null : ['sku' => $item['sku']];
            }
        );
        $collector = new ItemAuditCollector();

        $this->executor->run(
            $tool,
            [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']],
            true,
            $this->admin,
            auditCollector: $collector
        );

        self::assertSame([['A', 'ok', true], ['B', 'error', true], ['C', 'ok', true]], $seen);
        self::assertSame([
            'items' => [
                ['index' => 0, 'key' => 'A', 'status' => 'ok', 'detail' => ['sku' => 'A']],
                ['index' => 1, 'key' => 'B', 'status' => 'error', 'detail' => ['sku' => 'B']],
            ],
        ], $collector->toSummary());
    }

    public function testAuditDetailThatThrowsIsLoggedAndLeavesTheItemAlone(): void
    {
        $tool = $this->tool(BulkExecutorTestAuditTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok(5));
        $tool->method('getItemAuditDetail')->willThrowException(new RuntimeException('detail broke'));
        $this->logger->expects(self::once())->method('error')->with('MCP bulk item audit detail failed.');
        $collector = new ItemAuditCollector();

        $result = $this->executor->run($tool, [['sku' => 'A']], false, $this->admin, auditCollector: $collector);

        self::assertSame('ok', $result->rows()[0]['status']);
        self::assertSame(5, $result->rows()[0]['id']);
        self::assertSame(['items' => []], $collector->toSummary());
    }

    public function testToolWithoutAuditDetailLeavesTheCollectorUnused(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $collector = new ItemAuditCollector();

        $this->executor->run($tool, [['sku' => 'A']], false, $this->admin, auditCollector: $collector);

        self::assertSame([], $collector->toSummary());
    }

    public function testAuditDetailIsNotAskedForWithoutACollector(): void
    {
        $tool = $this->tool(BulkExecutorTestAuditTool::class);
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->expects(self::never())->method('getItemAuditDetail');

        $this->executor->run($tool, [['sku' => 'A']], false, $this->admin);
    }

    /**
     * PR #48 review round 3 (R1): the job hook runs before each item with its index and key; null runs the item.
     *
     * @return void
     */
    public function testBeforeItemRunsBeforeEachItemAndNullRunsIt(): void
    {
        $tool = $this->tool(BulkToolInterface::class);
        $order = [];
        $tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$order): ItemResult {
                $order[] = 'process ' . $item['sku'];
                return ItemResult::ok();
            }
        );

        $result = $this->executor->run(
            $tool,
            [['sku' => 'A'], ['sku' => 'B']],
            false,
            $this->admin,
            beforeItem: static function (int $index, ?string $key) use (&$order): ?ItemResult {
                $order[] = 'before ' . $index . ' ' . $key;
                return null;
            }
        );

        self::assertSame(['before 0 A', 'process A', 'before 1 B', 'process B'], $order);
        self::assertSame(['ok', 'ok'], array_column($result->rows(), 'status'));
    }

    /**
     * A result from the hook replaces the item: neither the item ACL nor processItem runs, no audit detail is
     * asked for, and the row reaches afterItem like any other.
     *
     * @return void
     */
    public function testBeforeItemResultIsUsedInsteadOfRunningTheItem(): void
    {
        $tool = $this->createMock(BulkExecutorTestAuditAclTool::class);
        $tool->method('getName')->willReturn('test.bulk');
        $tool->method('getItemKeyField')->willReturn('sku');
        $tool->expects(self::once())->method('getItemAclResources')->with(['sku' => 'B'])->willReturn([]);
        $processed = [];
        $tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );
        $tool->expects(self::once())->method('getItemAuditDetail')->willReturn(['sku' => 'B']);
        $seen = [];

        $result = $this->executor->run(
            $tool,
            [['sku' => 'A'], ['sku' => 'B']],
            false,
            $this->admin,
            0,
            static function (int $index, array $row) use (&$seen): void {
                $seen[] = $row;
            },
            auditCollector: new ItemAuditCollector(),
            beforeItem: static fn (int $index, ?string $key): ?ItemResult => $index === 0
                ? ItemResult::error(BulkErrorCode::INTERRUPTED, 'stopped mid-item')
                : null
        );

        self::assertSame(['B'], $processed);
        self::assertSame('error', $result->rows()[0]['status']);
        self::assertSame('INTERRUPTED', $result->rows()[0]['error_code']);
        self::assertSame('stopped mid-item', $result->rows()[0]['message']);
        self::assertSame([0, 1], array_column($seen, 'index'));
        self::assertSame('INTERRUPTED', $seen[0]['error_code']);
    }

    /**
     * A hook that throws stops the run before its item: the item never runs, afterBatch still runs as for any
     * other run-stopping error, and the throw reaches the caller after it.
     *
     * @return void
     */
    public function testBeforeItemThrowStopsBeforeTheItemRunsAfterBatchAndRethrows(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $processed = [];
        $tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );
        $tool->expects(self::once())->method('afterBatch')->with(
            self::callback(static fn (BatchResult $r): bool => array_column($r->rows(), 'key') === ['A'])
        );
        $error = new RuntimeException('marker write failed');

        try {
            $this->executor->run(
                $tool,
                [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']],
                false,
                $this->admin,
                beforeItem: static function (int $index) use ($error): ?ItemResult {
                    if ($index === 1) {
                        throw $error;
                    }
                    return null;
                }
            );
            self::fail('The hook error was swallowed.');
        } catch (RuntimeException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame(['A'], $processed);
    }

    /**
     * A run that lost its job skips afterBatch (beforeAfterBatch answers false); the hook's throw still reaches the
     * caller.
     *
     * @return void
     */
    public function testBeforeItemThrowWithAfterBatchRefusedStillRethrows(): void
    {
        $tool = $this->tool(BulkExecutorTestLifecycleTool::class);
        $tool->expects(self::never())->method('processItem');
        $tool->expects(self::never())->method('afterBatch');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lost');
        $this->executor->run(
            $tool,
            [['sku' => 'A']],
            false,
            $this->admin,
            beforeAfterBatch: static fn (): bool => false,
            beforeItem: static function (): ?ItemResult {
                throw new RuntimeException('lost');
            }
        );
    }
}
