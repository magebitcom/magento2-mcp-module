<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Job\Job;
use Magebit\Mcp\Model\Job\JobConnection;
use Magebit\Mcp\Model\Job\JobFactory;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\ResourceModel\Job as JobResource;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JobRepositoryTest extends TestCase
{
    /**
     * @phpstan-var AdapterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdapterInterface&MockObject $connection;

    /**
     * @phpstan-var JobFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobFactory&MockObject $factory;

    private JobRepository $repository;

    /**
     * @var array<string, mixed>
     */
    private array $updated = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('quote')->willReturnCallback(fn ($v): string => "'" . $v . "'");
        $this->connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $v): string => str_replace('?', "'" . $v . "'", $text)
        );
        $this->connection->method('quoteIdentifier')->willReturnCallback(fn ($v): string => '`' . $v . '`');
        $resource = $this->createMock(JobResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('magebit_mcp_job');
        $resource->method('getTable')->willReturnArgument(0);
        $this->factory = $this->createMock(JobFactory::class);
        $this->factory->method('create')->willReturnCallback(
            fn (array $args): Job => $this->buildJob($args['data'] ?? [])
        );
        $generator = $this->createMock(IdentityGeneratorInterface::class);
        $generator->method('generateId')->willReturn('11111111-2222-3333-4444-555555555555');
        $this->repository = new JobRepository($resource, $this->factory, $generator);
    }

    /**
     * @return void
     */
    public function testCreateSetsQueuedAndTotal(): void
    {
        $inserted = [];
        $this->connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $this->assertSame('magebit_mcp_job', $table);
                $inserted = $row;
                return 1;
            });

        $job = $this->repository->create('catalog.product.update', 7, 3, null, true, [['a' => 1], ['a' => 2]]);

        $this->assertSame('11111111-2222-3333-4444-555555555555', $inserted['job_id']);
        $this->assertSame('queued', $inserted['state']);
        $this->assertSame(2, $inserted['total']);
        $this->assertSame(0, $inserted['next_index']);
        $this->assertSame(1, $inserted['dry_run']);
        $this->assertSame(JobInterface::STATE_QUEUED, $job->getState());
        $this->assertSame(2, $job->getTotal());
        $this->assertTrue($job->isDryRun());
        $this->assertSame([['a' => 1], ['a' => 2]], $job->getItems());
        $this->assertNull($job->getOauthClientId());
    }

    /**
     * PR #48 review round 5 (S1): the input column holds the largest request the module accepts, even after the
     * escaped encoding inflates it.
     *
     * @return void
     */
    public function testInputCeilingCoversTheLargestAcceptedRequestAndFitsTheColumn(): void
    {
        $this->assertSame(ModuleConfig::MAX_MAX_BODY_KB * 1024 * 3, JobRepository::MAX_INPUT_BYTES);
        $this->assertLessThan(4294967295, JobRepository::MAX_INPUT_BYTES, 'LONGTEXT holds 4 GiB - 1 bytes');
    }

    /**
     * The input is stored as escaped (pure ASCII) JSON, so it fits any column charset and its byte length is
     * what the ceiling measures.
     *
     * @return void
     */
    public function testCreateStoresEscapedAsciiJson(): void
    {
        $inserted = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            });

        $this->repository->create('t.x', 7, 3, null, false, [['name' => "caf\u{e9} \u{1F600}"]]);

        $this->assertSame('[{"name":"caf\\u00e9 \\ud83d\\ude00"}]', $inserted['items_json']);
    }

    /**
     * @return void
     */
    public function testCreateAcceptsAnInputExactlyAtTheCeiling(): void
    {
        $inserted = [];
        $this->connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$inserted): int {
                $inserted = $row;
                return 1;
            });

        // ["aaa…"] is the string plus four bytes.
        $this->repositoryWithCeiling(100)->create('t.x', 7, 3, null, false, [str_repeat('a', 96)]);

        $this->assertSame('["' . str_repeat('a', 96) . '"]', $inserted['items_json']);
    }

    /**
     * @return void
     */
    public function testCreateRefusesAnInputOverTheCeilingBeforeTheInsert(): void
    {
        $this->connection->expects($this->never())->method('insert');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The job input is too large to store; send fewer items per call.');

        $this->repositoryWithCeiling(100)->create('t.x', 7, 3, null, false, [str_repeat('a', 97)]);
    }

    /**
     * Escaping counts: two characters under the ceiling unescaped are far over it escaped.
     *
     * @return void
     */
    public function testCreateMeasuresTheEscapedInput(): void
    {
        $this->connection->expects($this->never())->method('insert');
        $this->expectException(LocalizedException::class);

        $this->repositoryWithCeiling(100)->create('t.x', 7, 3, null, false, [str_repeat("\u{e9}", 20)]);
    }

    /**
     * @param int $ceiling
     * @return JobRepository
     */
    private function repositoryWithCeiling(int $ceiling): JobRepository
    {
        $resource = $this->createMock(JobResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('magebit_mcp_job');
        $generator = $this->createMock(IdentityGeneratorInterface::class);
        $generator->method('generateId')->willReturn('11111111-2222-3333-4444-555555555555');
        return new JobRepository($resource, $this->factory, $generator, $ceiling);
    }

    /**
     * @return void
     */
    public function testGetByJobIdThrowsWhenMissing(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn(false);
        $this->expectException(NoSuchEntityException::class);
        $this->repository->getByJobId('missing');
    }

    /**
     * @return void
     */
    public function testClaimReturnsFalseWhenNoRowChanged(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with('magebit_mcp_job', $this->anything(), ['job_id = ?' => 'j1', 'state = ?' => 'queued'])
            ->willReturn(0);
        $this->assertFalse($this->repository->claim('j1', 'r1'));
    }

    /**
     * @return void
     */
    public function testClaimReturnsTrueWhenRowChanged(): void
    {
        $this->captureUpdateData();
        $this->assertTrue($this->repository->claim('j1', 'r1'));
        $this->assertSame('running', $this->updated['state']);
        $this->assertSame('r1', $this->updated['run_id']);
    }

    /**
     * @return void
     */
    public function testFinishCompletedClearsItems(): void
    {
        $this->captureUpdateData();
        $this->repository->finish('j1', 'r1', JobInterface::STATE_COMPLETED, null);
        $this->assertArrayHasKey('items_json', $this->updated);
        $this->assertNull($this->updated['items_json']);
        $this->assertArrayHasKey('finished_at', $this->updated);
    }

    /**
     * @return void
     */
    public function testFinishCancelledKeepsItems(): void
    {
        $this->captureUpdateData();
        $this->repository->finish('j1', 'r1', JobInterface::STATE_CANCELLED, null);
        $this->assertArrayNotHasKey('items_json', $this->updated);
        $this->assertSame('cancelled', $this->updated['state']);
    }

    /**
     * @return void
     */
    public function testFinishFailedKeepsItems(): void
    {
        $this->captureUpdateData();
        $this->repository->finish('j1', 'r1', JobInterface::STATE_FAILED, 'boom');
        $this->assertArrayNotHasKey('items_json', $this->updated);
        $this->assertSame('boom', $this->updated['error']);
    }

    /**
     * @return void
     */
    public function testRequeueOnlyFromFailedOrCancelled(): void
    {
        $where = [];
        $data = [];
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$where, &$data): int {
                $data = $d;
                $where = $w;
                return 0;
            });

        $this->assertFalse($this->repository->requeue('j1'));
        $this->assertSame(['failed', 'cancelled'], $where['state IN (?)']);
        $this->assertContains('items_json IS NOT NULL', $where);
        $this->assertSame('queued', $data['state']);
        $this->assertSame(0, $data['cancel_requested']);
        $this->assertNull($data['error']);
        $this->assertNull($data['finished_at']);
        $this->assertArrayHasKey('run_id', $data);
        $this->assertNull($data['run_id']);
        // Review L1: the no-worker notice counts from the last time the job was queued.
        $this->assertInstanceOf(Expression::class, $data['heartbeat_at']);
        $this->assertSame('NOW()', (string) $data['heartbeat_at']);
    }

    /**
     * @return void
     */
    public function testCancelIfQueuedIsConditionalOnQueued(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with('magebit_mcp_job', $this->anything(), ['job_id = ?' => 'j1', 'state = ?' => 'queued'])
            ->willReturn(1);
        $this->assertTrue($this->repository->cancelIfQueued('j1'));
    }

    /**
     * @return void
     */
    public function testFailQueuedOnlyTouchesQueuedJobs(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with(
                'magebit_mcp_job',
                $this->callback(
                    fn (array $d): bool => $d['state'] === 'failed' && $d['error'] === 'boom'
                        && $d['finished_at'] instanceof Expression
                ),
                ['job_id = ?' => 'j1', 'state = ?' => 'queued']
            )
            ->willReturn(1);
        $this->assertTrue($this->repository->failQueued('j1', 'boom'));
    }

    /**
     * @return void
     */
    public function testFailQueuedReturnsFalseWhenJobWasClaimed(): void
    {
        $this->connection->method('update')->willReturn(0);
        $this->assertFalse($this->repository->failQueued('j1', 'boom'));
    }

    /**
     * @return void
     */
    public function testCancelOpenForConnectionsIsOneUpdateForTheWholeBatch(): void
    {
        $where = [];
        $this->connection->expects($this->once())->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$where): int {
                $where = $w;
                return 3;
            });

        $this->assertSame(3, $this->repository->cancelOpenForConnections([
            JobConnection::oauth(7, 4),
            JobConnection::personal(99),
            JobConnection::oauth(8, 4),
            JobConnection::personal(100),
        ]));
        $this->assertSame(
            [
                '(admin_user_id = 7 AND oauth_client_id = 4) OR (admin_user_id = 8 AND oauth_client_id = 4)'
                    . ' OR token_id IN (99, 100)',
                'state IN (?)' => ['queued', 'running'],
            ],
            $where
        );
    }

    /**
     * @return void
     */
    public function testCancelOpenForOnlyPersonalConnections(): void
    {
        $where = [];
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$where): int {
                $where = $w;
                return 1;
            });

        $this->assertSame(1, $this->repository->cancelOpenForConnections([JobConnection::personal(99)]));
        $this->assertSame('token_id IN (99)', $where[0]);
    }

    /**
     * @return void
     */
    public function testNoConnectionsRunNoQuery(): void
    {
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('select');

        $this->assertSame(0, $this->repository->cancelOpenForConnections([]));
        $this->assertSame([], $this->repository->openJobsForConnections([]));
    }

    /**
     * Review M1: a client-wide revoke cancels the client's open jobs of every admin.
     *
     * @return void
     */
    public function testCancelOpenForClientMatchesClientOfEveryAdmin(): void
    {
        $data = [];
        $where = [];
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$data, &$where): int {
                $data = $d;
                $where = $w;
                return 3;
            });

        $this->assertSame(3, $this->repository->cancelOpenForClient(4));
        $this->assertSame(
            ['oauth_client_id = ?' => 4, 'state IN (?)' => ['queued', 'running']],
            $where
        );
        $this->assertSame(1, $data['cancel_requested']);
        $this->assertInstanceOf(Expression::class, $data['state']);
        $this->assertInstanceOf(Expression::class, $data['finished_at']);
        $this->assertSame(['finished_at', 'state', 'cancel_requested'], array_keys($data));
    }

    /**
     * @return void
     */
    public function testPurgeSkipsRunningJobs(): void
    {
        $this->connection->expects($this->once())->method('delete')
            ->with(
                'magebit_mcp_job',
                ['created_at < DATE_SUB(NOW(), INTERVAL ? DAY)' => 30, 'state != ?' => 'running']
            )
            ->willReturn(5);
        $this->assertSame(5, $this->repository->purgeOlderThan(30));
    }

    /**
     * @return void
     */
    public function testRecordItemAdvancesOwnedRunThenWritesRowAndCounters(): void
    {
        $calls = [];
        $order = [];
        $this->givenOrderedTransaction($order);
        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->expects($this->once())->method('fetchRow')
            ->willReturnCallback(function () use (&$order): array {
                $order[] = 'read item';
                return ['status' => 'running'];
            });
        $this->connection->expects($this->exactly(2))->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$calls, &$order): int {
                $order[] = 'update';
                $calls[] = ['update', $d, $w];
                return 1;
            });
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $rows) use (&$calls, &$order): int {
                $order[] = 'insert';
                $calls[] = ['insert', $rows, $t];
                return 1;
            });

        $this->assertTrue($this->repository->recordItem(
            'j1',
            'r1',
            ['index' => 2, 'key' => 'sku-2', 'status' => 'ok', 'id' => 15],
            3
        ));

        $owned = ['job_id = ?' => 'j1', 'state = ?' => 'running', 'run_id = ?' => 'r1'];
        $this->assertSame(['begin', 'update', 'read item', 'insert', 'update', 'commit'], $order);
        $this->assertCount(3, $calls);
        [$kind, $data, $where] = $calls[0];
        $this->assertSame('update', $kind);
        $this->assertSame(3, $data['next_index']);
        $this->assertArrayHasKey('heartbeat_at', $data);
        $this->assertSame($owned, $where);
        $this->assertSame(['insert', [[
            'job_id' => 'j1', 'item_index' => 2, 'item_key' => 'sku-2', 'status' => 'ok',
            'error_code' => null, 'message' => null, 'result_id' => '15',
        ]], 'magebit_mcp_job_item'], $calls[1]);
        [$kind, $data, $where] = $calls[2];
        $this->assertSame('update', $kind);
        $this->assertSame(['ok' => '`ok` + 1'], $this->expressions($data), 'the marker it replaced was never counted');
        $this->assertSame($owned, $where);
    }

    /**
     * PR #48 review round 4 (P1): the previous status of the item row is a plain read under the job row lock the
     * advance already holds, like markItemRunning's; a locking read of a missing row would add a gap lock.
     *
     * @return void
     */
    public function testRecordItemReadsThePreviousStatusWithoutALock(): void
    {
        $conditions = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $select->expects($this->never())->method('forUpdate');
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('update')->willReturn(1);
        $this->connection->method('insertOnDuplicate')->willReturn(1);

        $this->repository->recordItem('j1', 'r1', ['index' => 5, 'key' => null, 'status' => 'skipped'], 6);

        $this->assertSame([['job_id = ?', 'j1'], ['item_index = ?', 5]], $conditions);
    }

    /**
     * @return array<string, array{0: array<string, string>|false, 1: string, 2: array<string, string>}>
     */
    public static function counterDeltaProvider(): array
    {
        return [
            'no row yet adds one' => [false, 'error', ['failed' => '`failed` + 1']],
            'the marker adds one' => [['status' => 'running'], 'skipped', ['skipped' => '`skipped` + 1']],
            'a terminal row is moved' => [
                ['status' => 'ok'],
                'error',
                ['ok' => 'GREATEST(`ok`, 1) - 1', 'failed' => '`failed` + 1'],
            ],
            'a skipped row is moved' => [
                ['status' => 'skipped'],
                'ok',
                ['ok' => '`ok` + 1', 'skipped' => 'GREATEST(`skipped`, 1) - 1'],
            ],
        ];
    }

    /**
     * PR #48 review round 4 (P1): the counters move by the item's previous and new status, never by a recount.
     *
     * @dataProvider counterDeltaProvider
     * @param array<string, string>|false $previous
     * @param string $status
     * @param array<string, string> $expected
     * @return void
     */
    public function testRecordItemMovesTheCountersByDelta(array|false $previous, string $status, array $expected): void
    {
        $updates = [];
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn($previous);
        $this->connection->method('insertOnDuplicate')->willReturn(1);
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$updates): int {
                $updates[] = [$d, $w];
                return 1;
            });

        $this->repository->recordItem('j1', 'r1', ['index' => 0, 'key' => null, 'status' => $status], 1);

        $this->assertCount(2, $updates);
        $this->assertSame($expected, $this->expressions($updates[1][0]));
        $this->assertSame(['job_id = ?' => 'j1', 'state = ?' => 'running', 'run_id = ?' => 'r1'], $updates[1][1]);
    }

    /**
     * PR #48 review round 4 (P1): a duplicate write of the same result (redelivery) nets zero, so the counter
     * UPDATE is skipped.
     *
     * @return void
     */
    public function testRecordItemOverwritingTheSameStatusLeavesTheCountersAlone(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn(['status' => 'ok']);
        $this->connection->expects($this->once())->method('insertOnDuplicate')->willReturn(1);
        $this->connection->expects($this->once())->method('update')->willReturn(1);

        $this->assertTrue(
            $this->repository->recordItem('j1', 'r1', ['index' => 0, 'key' => null, 'status' => 'ok'], 1)
        );
    }

    /**
     * PR #48 review round 4 (P1): a sequence of writes against an in-memory item table, with the counter
     * expressions applied to in-memory counters, ends with the counters a recount would give, and no per-item
     * write ever counts the item table.
     *
     * @return void
     */
    public function testCounterDeltasMatchARecountOverASequence(): void
    {
        /** @var array<int, string> $items */
        $items = [];
        $counters = ['ok' => 0, 'failed' => 0, 'skipped' => 0];
        $index = null;
        $sql = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$index, $select): Select {
                if ($c === 'item_index = ?') {
                    $index = $v;
                }
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnCallback(
            function () use (&$items, &$index) {
                $status = is_int($index) ? ($items[$index] ?? null) : null;
                return $status !== null ? ['status' => $status, 'error_code' => null] : false;
            }
        );
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $t, array $rows) use (&$items): int {
                foreach ($rows as $row) {
                    $items[$row['item_index']] = $row['status'];
                }
                return 1;
            }
        );
        $this->connection->method('update')->willReturnCallback(
            function (string $t, array $d) use (&$counters, &$sql): int {
                foreach ($d as $column => $value) {
                    if (!isset($counters[$column])) {
                        continue;
                    }
                    $text = (string) $value;
                    $sql[] = $text;
                    if (preg_match('/^`(\w+)` \+ 1$/', $text) === 1) {
                        $counters[$column]++;
                    } elseif (preg_match('/^GREATEST\(`(\w+)`, 1\) - 1$/', $text) === 1) {
                        $counters[$column] = max($counters[$column], 1) - 1;
                    } else {
                        $this->fail('Unexpected counter expression: ' . $text);
                    }
                }
                return 1;
            }
        );

        $record = function (int $i, string $status): void {
            $this->repository->markItemRunning('j1', 'r1', $i, null);
            $this->repository->recordItem('j1', 'r1', ['index' => $i, 'key' => null, 'status' => $status], $i + 1);
        };
        $record(0, 'ok');
        $record(1, 'error');
        $record(2, 'skipped');
        // Redelivery of item 1 with a new result, and a duplicate write of item 0.
        $this->repository->recordItem('j1', 'r1', ['index' => 1, 'key' => null, 'status' => 'ok'], 2);
        $this->repository->recordItem('j1', 'r1', ['index' => 0, 'key' => null, 'status' => 'ok'], 1);
        // A cancelled (skipped) item run again on resume: the marker drops it, the result counts it.
        $record(2, 'error');

        $recount = ['ok' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($items as $status) {
            $column = ['ok' => 'ok', 'error' => 'failed', 'skipped' => 'skipped'][$status] ?? null;
            if ($column !== null) {
                $recount[$column]++;
            }
        }
        $this->assertSame(['ok' => 2, 'failed' => 1, 'skipped' => 0], $counters);
        $this->assertSame($recount, $counters);
        foreach ($sql as $text) {
            $this->assertStringNotContainsString('SELECT', $text);
            $this->assertStringNotContainsString('COUNT', $text);
        }
    }

    /**
     * PR #48 review: the cursor, the item row and the counters commit together. A failed item write rolls the
     * cursor back, so a resume starts at the old next_index and the item is not lost.
     *
     * @return void
     */
    public function testRecordItemRollsBackTheCursorWhenTheItemWriteFails(): void
    {
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn(['status' => 'running']);
        $this->connection->expects($this->once())->method('update')->willReturn(1);
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willThrowException(new \RuntimeException('item write failed'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('item write failed');
        $this->repository->recordItem('j1', 'r1', ['index' => 0, 'key' => null, 'status' => 'ok'], 1);
    }

    /**
     * Review L3: item_key is varchar(255), error_code and result_id varchar(64). Strict MySQL would throw on a
     * longer value after next_index already advanced, so the row is bounded (by characters, not bytes).
     *
     * @return void
     */
    public function testRecordItemBoundsTheItemColumns(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('update')->willReturn(1);
        $rows = [];
        $this->connection->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $r) use (&$rows): int {
                $rows = $r;
                return 1;
            });

        $this->repository->recordItem(
            'j1',
            'r1',
            [
                'index' => 0,
                'key' => str_repeat('ā', 300),
                'status' => 'error',
                'error_code' => str_repeat('E', 100),
                'message' => str_repeat('m', 1000),
                'id' => str_repeat('9', 100),
            ],
            1
        );

        $row = $rows[0];
        $this->assertSame(str_repeat('ā', 255), $row['item_key']);
        $this->assertSame(str_repeat('E', 64), $row['error_code']);
        $this->assertSame(str_repeat('9', 64), $row['result_id']);
        $this->assertSame(1000, strlen($row['message']), 'message is a text column and stays whole');
    }

    /**
     * Review Focus 2: a run that lost the job writes no item row, so the new owner's row is never overwritten.
     *
     * @return void
     */
    public function testRecordItemWritesNothingWhenRunNoLongerOwnsJob(): void
    {
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('update')->willReturn(0);
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->expects($this->once())->method('commit');
        $this->assertFalse(
            $this->repository->recordItem('j1', 'r-old', ['index' => 0, 'key' => null, 'status' => 'ok'], 1)
        );
    }

    /**
     * @return void
     */
    public function testFinishIsGuardedByOwnedRunAndReturnsFalseWhenNoRowChanged(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with(
                'magebit_mcp_job',
                $this->anything(),
                ['job_id = ?' => 'j1', 'state = ?' => 'running', 'run_id = ?' => 'r1']
            )
            ->willReturn(0);
        $this->assertFalse($this->repository->finish('j1', 'r1', JobInterface::STATE_COMPLETED, null));
    }

    /**
     * PR #48 review: the stale cron fails a job only while its heartbeat is still stale, in the same UPDATE that
     * checks the owned run, so a heartbeat that arrives after the lookup keeps the run alive.
     *
     * @return void
     */
    public function testFailStaleIsGuardedByOwnedRunAndStaleHeartbeat(): void
    {
        $data = [];
        $where = [];
        // The job UPDATE of each call, plus the leftover conversion after the one that failed the job.
        $this->connection->expects($this->exactly(3))->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$data, &$where): int {
                if ($t !== 'magebit_mcp_job') {
                    return 0;
                }
                $data = $d;
                $where = $w;
                return $w['run_id = ?'] === 'r1' ? 1 : 0;
            });

        $this->assertTrue($this->repository->failStale('j1', 'r1', 600, 'stale'));
        $this->assertSame(
            [
                'job_id = ?' => 'j1',
                'state = ?' => 'running',
                'run_id = ?' => 'r1',
                'heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)' => 600,
            ],
            $where
        );
        $this->assertSame('failed', $data['state']);
        $this->assertSame('stale', $data['error']);
        $this->assertInstanceOf(Expression::class, $data['finished_at']);
        $this->assertSame('NOW()', (string) $data['finished_at']);
        $this->assertArrayNotHasKey('items_json', $data, 'a failed job keeps its input for a resume');

        $this->assertFalse($this->repository->failStale('j1', 'r-fresh', 600, 'stale'));
    }

    /**
     * @return void
     */
    public function testHeartbeatIsGuardedByOwnedRun(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with(
                'magebit_mcp_job',
                $this->callback(fn (array $d): bool => $d['heartbeat_at'] instanceof Expression),
                ['job_id = ?' => 'j1', 'state = ?' => 'running', 'run_id = ?' => 'r1']
            )
            ->willReturn(1);
        $this->connection->expects($this->never())->method('fetchOne');
        $this->assertTrue($this->repository->heartbeat('j1', 'r1'));
    }

    /**
     * MySQL reports 0 changed rows when the heartbeat lands in the same second as the claim.
     *
     * @return void
     */
    public function testHeartbeatWithNoChangedRowChecksOwnership(): void
    {
        $this->connection->method('update')->willReturn(0);
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls('12', false);
        $this->assertTrue($this->repository->heartbeat('j1', 'r1'));
        $this->assertFalse($this->repository->heartbeat('j1', 'r-old'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string> The counter columns of an UPDATE, as SQL text.
     */
    private function expressions(array $data): array
    {
        $texts = [];
        foreach (['ok', 'failed', 'skipped'] as $column) {
            if (array_key_exists($column, $data)) {
                $this->assertInstanceOf(Expression::class, $data[$column]);
                $texts[$column] = (string) $data[$column];
            }
        }
        return $texts;
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private function assertCountersRecomputed(array $data): void
    {
        foreach (['ok' => 'ok', 'failed' => 'error', 'skipped' => 'skipped'] as $column => $status) {
            $this->assertInstanceOf(Expression::class, $data[$column]);
            $sql = (string) $data[$column];
            $this->assertStringContainsString('SELECT COUNT(*) FROM `magebit_mcp_job_item`', $sql);
            $this->assertStringContainsString("job_id = 'j1'", $sql);
            $this->assertStringContainsString("status = '" . $status . "'", $sql);
        }
    }

    /**
     * PR #48 review: the job row is locked and still `cancelled` before the skipped rows and counters are written,
     * all in one transaction, and the counter update is guarded by the cancelled state.
     *
     * @return void
     */
    public function testMarkRemainingCancelledWritesSkippedRows(): void
    {
        $calls = [];
        $conditions = [];
        $select = $this->lockSelectMock($calls, $conditions);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects($this->once())->method('fetchRow')
            ->willReturnCallback(function () use (&$calls): array {
                $calls[] = 'fetchRow';
                return ['state' => 'cancelled', 'total' => '5'];
            });
        $this->connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$calls): AdapterInterface {
                $calls[] = 'begin';
                return $this->connection;
            });
        $rows = [];
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $r) use (&$rows, &$calls): int {
                $calls[] = 'insert';
                $rows = $r;
                return count($r);
            });
        $data = [];
        $where = [];
        $this->connection->expects($this->once())->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$data, &$where, &$calls): int {
                $calls[] = 'update';
                $data = $d;
                $where = $w;
                return 1;
            });
        $this->connection->expects($this->once())->method('commit')
            ->willReturnCallback(function () use (&$calls): AdapterInterface {
                $calls[] = 'commit';
                return $this->connection;
            });
        $this->connection->expects($this->never())->method('rollBack');

        $this->repository->markRemainingCancelled('j1', 3, [3 => 'a']);

        $this->assertSame(['begin', 'forUpdate', 'fetchRow', 'insert', 'update', 'commit'], $calls);
        $this->assertContains(['job_id = ?', 'j1'], $conditions);
        $this->assertCountersRecomputed($data);
        $this->assertSame(['job_id = ?' => 'j1', 'state = ?' => 'cancelled'], $where);

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]['item_index']);
        $this->assertSame('a', $rows[0]['item_key']);
        $this->assertSame('skipped', $rows[1]['status']);
        $this->assertSame('CANCELLED', $rows[1]['error_code']);
    }

    /**
     * PR #48 review: a job resumed before the cleanup took the lock is no longer `cancelled`; the cleanup then
     * writes nothing, so it never overwrites the resumed run's results or counters.
     *
     * @return void
     */
    public function testMarkRemainingCancelledLeavesAResumedJobAlone(): void
    {
        $calls = [];
        $conditions = [];
        $this->connection->method('select')->willReturn($this->lockSelectMock($calls, $conditions));
        $this->connection->expects($this->once())->method('fetchRow')
            ->willReturn(['state' => 'queued', 'total' => '5']);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->never())->method('update');
        // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
        $this->connection->expects($this->never())->method('rollBack');
        $this->connection->expects($this->once())->method('commit');

        $this->repository->markRemainingCancelled('j1', 0, []);

        $this->assertSame(['forUpdate'], $calls);
    }

    /**
     * PR #48 review lap: a cleanup that stalled across cancel, resume, cancel again must not mark items the later
     * run already finished: the locked `next_index` wins over a smaller caller index.
     *
     * @return void
     */
    public function testMarkRemainingCancelledStartsAtTheLockedNextIndex(): void
    {
        $calls = [];
        $conditions = [];
        $this->connection->method('select')->willReturn($this->lockSelectMock($calls, $conditions));
        $this->connection->expects($this->once())->method('fetchRow')
            ->willReturn(['state' => 'cancelled', 'total' => '5', 'next_index' => '4']);
        $rows = [];
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $r) use (&$rows): int {
                $rows = $r;
                return count($r);
            });
        $this->connection->expects($this->once())->method('update')->willReturn(1);
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->repository->markRemainingCancelled('j1', 1, []);

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['item_index']);
    }

    /**
     * @return void
     */
    public function testMarkRemainingCancelledRollsBackAndRethrows(): void
    {
        $calls = [];
        $conditions = [];
        $this->connection->method('select')->willReturn($this->lockSelectMock($calls, $conditions));
        $this->connection->method('fetchRow')->willReturn(['state' => 'cancelled', 'total' => '2']);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willThrowException(new \RuntimeException('lock wait timeout'));
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lock wait timeout');
        $this->repository->markRemainingCancelled('j1', 0, []);
    }

    /**
     * @param list<string> $calls
     * @param list<array{0: string, 1: mixed}> $conditions
     * @return Select&MockObject
     */
    private function lockSelectMock(array &$calls, array &$conditions): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $select->expects($this->once())->method('forUpdate')->with(true)
            ->willReturnCallback(function () use (&$calls, $select): Select {
                $calls[] = 'forUpdate';
                return $select;
            });
        return $select;
    }

    /**
     * @return void
     */
    public function testListForAdminFiltersByAllowedToolsBeforePaging(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limitPage')->willReturnSelf();
        $conditions = [];
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[$c] = $v;
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn('0');

        $this->repository->listForAdmin(7, null, null, 1, 10, ['a.b', 'c.d']);

        $this->assertSame(['a.b', 'c.d'], $conditions['tool_name IN (?)']);
        $this->assertSame(7, $conditions['admin_user_id = ?']);
    }

    /**
     * @return void
     */
    public function testListForAdminWithNoAllowedToolsRunsNoQuery(): void
    {
        $this->connection->expects($this->never())->method('select');
        $this->assertSame(['items' => [], 'total' => 0], $this->repository->listForAdmin(7, null, null, 1, 10, []));
    }

    /**
     * @return void
     */
    public function testSecondsSinceQueuedCountsFromTheLastQueueing(): void
    {
        $columns = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(
            function (string $table, array $cols) use (&$columns, $select): Select {
                $columns = $cols;
                return $select;
            }
        );
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('301');

        $this->assertSame(301, $this->repository->secondsSinceQueued('j1'));
        $this->assertSame(
            'TIMESTAMPDIFF(SECOND, COALESCE(heartbeat_at, created_at), NOW())',
            (string) $columns[0]
        );
    }

    /**
     * Review L2: the canceller collects the queued jobs it is about to cancel, by the same match as the cancel.
     *
     * @return void
     */
    public function testQueuedJobIdsMatchLikeTheCancel(): void
    {
        $conditions = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->willReturn(['q1', 'q2']);

        $this->assertSame(['q1', 'q2'], $this->repository->queuedJobIdsForClient(4));
        $this->assertSame([['oauth_client_id = ?', 4], ['state = ?', 'queued']], $conditions);
    }

    /**
     * PR #48 review round 5 (S2): one query finds the open jobs of a whole batch of connections, by the same match
     * as the cancel, with their state so the cleanup can tell the queued ones.
     *
     * @return void
     */
    public function testOpenJobsForConnectionsIsOneQueryForTheWholeBatch(): void
    {
        $columns = null;
        $conditions = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(
            function (string $t, array $c) use (&$columns, $select): Select {
                $columns = $c;
                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $select->method('order')->willReturnSelf();
        $this->connection->expects($this->once())->method('select')->willReturn($select);
        $this->connection->expects($this->once())->method('fetchPairs')
            ->willReturn(['q1' => 'queued', 'r1' => 'running']);

        $this->assertSame(
            ['q1' => 'queued', 'r1' => 'running'],
            $this->repository->openJobsForConnections([JobConnection::oauth(7, 4), JobConnection::personal(99)])
        );
        $this->assertSame(['job_id', 'state'], $columns);
        $this->assertSame(
            [
                ['(admin_user_id = 7 AND oauth_client_id = 4) OR token_id IN (99)', null],
                ['state IN (?)', ['queued', 'running']],
            ],
            $conditions
        );
    }

    /**
     * PR #48 review round 4 (P2): the revoke cleanup reads one page of jobs in one query: only the given ids that
     * are still cancelled, only the columns the cleanup needs (no counters, timestamps or error), in entity order.
     *
     * @return void
     */
    public function testCancelledJobsForCleanupReadsOnePageInOneQuery(): void
    {
        $columns = null;
        $conditions = [];
        $order = null;
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(
            function (string $t, array $c) use (&$columns, $select): Select {
                $columns = $c;
                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $select->method('order')->willReturnCallback(
            function ($o) use (&$order, $select): Select {
                $order = $o;
                return $select;
            }
        );
        $this->connection->expects($this->once())->method('select')->willReturn($select);
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([
            ['job_id' => 'q1', 'tool_name' => 't.x', 'state' => 'cancelled', 'next_index' => '2', 'items_json' => '[]'],
        ]);

        $jobs = $this->repository->cancelledJobsForCleanup(['q1', 'q2']);

        $this->assertSame(['entity_id', 'job_id', 'tool_name', 'state', 'next_index', 'total', 'items_json'], $columns);
        $this->assertSame([['job_id IN (?)', ['q1', 'q2']], ['state = ?', 'cancelled']], $conditions);
        $this->assertSame('entity_id ASC', $order);
        $this->assertCount(1, $jobs);
        $this->assertSame('q1', $jobs[0]->getJobId());
        $this->assertSame(2, $jobs[0]->getNextIndex());
    }

    /**
     * @return void
     */
    public function testCancelledJobsForCleanupWithNoIdsRunsNoQuery(): void
    {
        $this->connection->expects($this->never())->method('select');
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->repository->cancelledJobsForCleanup([]));
    }

    /**
     * @return void
     */
    private function captureUpdateData(): void
    {
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d): int {
                // The first UPDATE is the job row's; finish() may follow it with the leftover conversion.
                if ($this->updated === []) {
                    $this->updated = $d;
                }
                return 1;
            });
    }

    /**
     * @param array<string, mixed> $data
     * @return Job
     */
    private function buildJob(array $data): Job
    {
        $job = (new ObjectManager($this))->getObject(Job::class, ['data' => $data]);
        $this->assertInstanceOf(Job::class, $job);
        return $job;
    }

    /**
     * @return Select&MockObject
     */
    private function selectMock(): Select
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order', 'limitPage', 'forUpdate'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        return $select;
    }

    /**
     * Review lap 1 #3: a second save for the same job keeps the first row; the update list only rewrites the
     * unique key with itself.
     *
     * @return void
     */
    public function testSaveSnapshotNeverOverwritesAnExistingRow(): void
    {
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->with(
                'magebit_mcp_job_snapshot',
                [['job_id' => 'j1', 'format' => 'json', 'payload' => '{}']],
                ['job_id']
            )
            ->willReturn(1);

        $this->repository->saveSnapshot('j1', 'json', '{}');
    }

    /**
     * @return void
     */
    public function testHasSnapshotLooksUpTheJobsRow(): void
    {
        $conditions = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $c, $v = null) use (&$conditions, $select): Select {
                $conditions[] = [$c, $v];
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls('1', false);

        $this->assertTrue($this->repository->hasSnapshot('j1'));
        $this->assertFalse($this->repository->hasSnapshot('j1'));
        $this->assertContains(['job_id = ?', 'j1'], $conditions);
    }
    /**
     * PR #48 review round 3 (R1): before an item runs, the owned run is locked through its heartbeat, the item's
     * row is read, and the in-flight marker is written, in one transaction.
     *
     * @return void
     */
    public function testMarkItemRunningLocksTheOwnedRunThenWritesTheMarker(): void
    {
        $order = [];
        $updates = [];
        $inserted = [];
        $this->givenOrderedTransaction($order);
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->expects($this->once())->method('fetchRow')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'read item';
                return false;
            });
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$order, &$updates): int {
                $order[] = 'update ' . $t;
                $updates[] = [$t, $d, $w];
                return 1;
            });
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $rows) use (&$order, &$inserted): int {
                $order[] = 'insert ' . $t;
                $inserted = $rows;
                return 1;
            });
        $this->connection->expects($this->never())->method('rollBack');

        $this->assertSame(
            JobRepository::MARK_RUN,
            $this->repository->markItemRunning('j1', 'r1', 4, 'sku-4')
        );

        $this->assertSame(
            ['begin', 'update magebit_mcp_job', 'read item', 'insert magebit_mcp_job_item', 'commit'],
            $order,
            'no counter update: no counted row was replaced'
        );
        [, $data, $where] = $updates[0];
        $this->assertSame(['job_id = ?' => 'j1', 'state = ?' => 'running', 'run_id = ?' => 'r1'], $where);
        $this->assertInstanceOf(Expression::class, $data['heartbeat_at']);
        $this->assertArrayNotHasKey('next_index', $data, 'the cursor moves only when the result is recorded');
        $this->assertSame([[
            'job_id' => 'j1', 'item_index' => 4, 'item_key' => 'sku-4', 'status' => 'running',
            'error_code' => null, 'message' => null, 'result_id' => null,
        ]], $inserted);
    }

    /**
     * PR #48 review round 3 lap: the item row is read without a lock. The owned run's job row is already locked and
     * every writer of this job's item rows takes it first; a locking read of a missing row would add an InnoDB gap
     * lock that two consumers of different jobs can deadlock on.
     *
     * @return void
     */
    public function testMarkItemRunningReadsTheItemRowWithoutALock(): void
    {
        $this->connection->method('beginTransaction')->willReturnSelf();
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order', 'limitPage'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->expects($this->never())->method('forUpdate');
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('update')->willReturn(1);
        $this->connection->method('insertOnDuplicate')->willReturn(1);

        $this->assertSame(JobRepository::MARK_RUN, $this->repository->markItemRunning('j1', 'r1', 0, null));
    }

    /**
     * MySQL reports 0 changed rows when the heartbeat lands in the second it already holds; ownership is then
     * confirmed with a locking read before the marker is written.
     *
     * @return void
     */
    public function testMarkItemRunningConfirmsOwnershipWhenTheHeartbeatDidNotChange(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('update')->willReturn(0);
        $this->connection->expects($this->once())->method('fetchOne')->willReturn('12');
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->expects($this->once())->method('insertOnDuplicate');

        $this->assertSame(JobRepository::MARK_RUN, $this->repository->markItemRunning('j1', 'r1', 0, null));
    }

    /**
     * @return void
     */
    public function testMarkItemRunningWritesNothingWhenTheRunNoLongerOwnsTheJob(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('update')->willReturn(0);
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->expects($this->never())->method('fetchRow');
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->once())->method('beginTransaction');
        // Nothing was written: commit, not rollBack, so an enclosing transaction is never poisoned.
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->assertSame(JobRepository::MARK_LOST, $this->repository->markItemRunning('j1', 'r-old', 0, null));
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: string, 2: bool}>
     */
    public static function existingItemRowProvider(): array
    {
        return [
            'marker of a worker that stopped mid-item' => [
                ['status' => 'running', 'error_code' => null],
                JobRepository::MARK_INTERRUPTED,
                false,
            ],
            'already reported interrupted' => [
                ['status' => 'error', 'error_code' => 'INTERRUPTED'],
                JobRepository::MARK_INTERRUPTED,
                false,
            ],
            'skipped by a cancel, run on resume' => [
                ['status' => 'skipped', 'error_code' => 'CANCELLED'],
                JobRepository::MARK_RUN,
                true,
            ],
        ];
    }

    /**
     * @dataProvider existingItemRowProvider
     * @param array<string, string|null> $existing
     * @param string $expected
     * @param bool $writesMarker
     * @return void
     */
    public function testMarkItemRunningChecksTheItemsExistingRow(
        array $existing,
        string $expected,
        bool $writesMarker
    ): void {
        $this->connection->method('select')->willReturn($this->selectMock());
        $updates = [];
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$updates): int {
                $updates[] = $d;
                return 1;
            });
        $this->connection->method('fetchRow')->willReturn($existing);
        $this->connection->expects($writesMarker ? $this->once() : $this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->assertSame($expected, $this->repository->markItemRunning('j1', 'r1', 2, 'k'));

        if ($writesMarker) {
            // The replaced row was counted as skipped; the counters drop it while the item runs, by delta.
            $this->assertCount(2, $updates);
            $this->assertSame(['skipped' => 'GREATEST(`skipped`, 1) - 1'], $this->expressions($updates[1]));
        } else {
            $this->assertCount(1, $updates);
        }
    }

    /**
     * @return void
     */
    public function testMarkItemRunningRollsBackAndRethrows(): void
    {
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('update')->willReturn(1);
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('Deadlock'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deadlock');
        $this->repository->markItemRunning('j1', 'r1', 0, null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endStateProvider(): array
    {
        return [
            'failed' => [JobInterface::STATE_FAILED],
            'cancelled' => [JobInterface::STATE_CANCELLED],
            'completed' => [JobInterface::STATE_COMPLETED],
        ];
    }

    /**
     * A marker left by a run that stopped mid-item becomes error / INTERRUPTED when the job leaves `running`, in the
     * same transaction, and the counters follow, so rows and counters agree at rest.
     *
     * @dataProvider endStateProvider
     * @param string $state
     * @return void
     */
    public function testFinishTurnsALeftoverMarkerIntoInterrupted(string $state): void
    {
        $order = [];
        $updates = [];
        $this->givenOrderedTransaction($order);
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$order, &$updates): int {
                $order[] = 'update ' . $t;
                $updates[] = [$t, $d, $w];
                return 1;
            });
        $this->connection->expects($this->never())->method('rollBack');

        $this->assertTrue($this->repository->finish('j1', 'r1', $state, 'why'));

        $this->assertSame(
            ['begin', 'update magebit_mcp_job', 'update magebit_mcp_job_item', 'update magebit_mcp_job', 'commit'],
            $order
        );
        $this->assertSame($state, $updates[0][1]['state']);
        $this->assertInterruptedConversion($updates[1]);
        $this->assertCountersRecomputed($updates[2][1]);
        $this->assertSame(['job_id = ?' => 'j1'], $updates[2][2]);
    }

    /**
     * @return void
     */
    public function testFinishWithoutALeftoverMarkerLeavesTheCountersAlone(): void
    {
        $tables = [];
        $this->connection->method('update')
            ->willReturnCallback(function (string $t) use (&$tables): int {
                $tables[] = $t;
                return $t === 'magebit_mcp_job' ? 1 : 0;
            });

        $this->assertTrue($this->repository->finish('j1', 'r1', JobInterface::STATE_COMPLETED, null));

        $this->assertSame(['magebit_mcp_job', 'magebit_mcp_job_item'], $tables);
    }

    /**
     * @return void
     */
    public function testFailStaleTurnsALeftoverMarkerIntoInterrupted(): void
    {
        $updates = [];
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d, array $w) use (&$updates): int {
                $updates[] = [$t, $d, $w];
                return 1;
            });

        $this->assertTrue($this->repository->failStale('j1', 'r1', 600, 'stale'));

        $this->assertCount(3, $updates);
        $this->assertSame('failed', $updates[0][1]['state']);
        $this->assertInterruptedConversion($updates[1]);
        $this->assertCountersRecomputed($updates[2][1]);
    }

    /**
     * The in-flight row is not a result yet: item pages and their total leave it out.
     *
     * @return void
     */
    public function testGetItemsLeavesTheInFlightRowOut(): void
    {
        $selects = [];
        $this->connection->method('select')->willReturnCallback(function () use (&$selects): Select {
            $conditions = [];
            $select = $this->createMock(Select::class);
            foreach (['from', 'order', 'limitPage'] as $method) {
                $select->method($method)->willReturnSelf();
            }
            $select->method('where')->willReturnCallback(
                function (string $c, $v = null) use (&$selects, $select): Select {
                    $selects[spl_object_id($select)][$c] = $v;
                    return $select;
                }
            );
            return $select;
        });
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn('0');

        $this->repository->getItems('j1', 1, 20);

        $this->assertCount(2, $selects);
        foreach ($selects as $conditions) {
            $this->assertSame('j1', $conditions['job_id = ?']);
            $this->assertSame('running', $conditions['status != ?']);
        }
    }

    /**
     * Counters count error rows, INTERRUPTED included, and never the in-flight marker.
     *
     * @return void
     */
    public function testCountersCountInterruptedAsFailedAndNeverTheMarker(): void
    {
        $data = [];
        $this->connection->method('select')->willReturn($this->selectMock());
        $this->connection->method('fetchRow')->willReturn(['status' => 'running', 'error_code' => null]);
        $this->connection->method('update')
            ->willReturnCallback(function (string $t, array $d) use (&$data): int {
                $data[] = $d;
                return 1;
            });

        $this->repository->recordItem(
            'j1',
            'r1',
            ['index' => 0, 'key' => null, 'status' => 'error', 'error_code' => 'INTERRUPTED', 'message' => 'm'],
            1
        );

        $this->assertSame(['failed' => '`failed` + 1'], $this->expressions($data[1]));
    }

    /**
     * A cancel after a resume must not turn an INTERRUPTED row back into a skipped one: a later resume would then
     * run that item again.
     *
     * @return void
     */
    public function testMarkRemainingCancelledKeepsInterruptedRows(): void
    {
        $calls = [];
        $conditions = [];
        $this->connection->method('select')->willReturn($this->lockSelectMock($calls, $conditions));
        $this->connection->method('fetchRow')
            ->willReturn(['state' => 'cancelled', 'total' => '4', 'next_index' => '1']);
        $this->connection->expects($this->once())->method('fetchCol')->willReturn(['1', '3']);
        $rows = [];
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->willReturnCallback(function (string $t, array $r) use (&$rows): int {
                $rows = $r;
                return count($r);
            });
        $this->connection->method('update')->willReturn(1);

        $this->repository->markRemainingCancelled('j1', 1, []);

        $this->assertSame([2], array_column($rows, 'item_index'));
        $this->assertContains(["status = 'running' OR error_code = 'INTERRUPTED'", null], $conditions);
        $this->assertContains(['item_index >= ?', 1], $conditions);
    }

    /**
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} $update
     * @return void
     */
    private function assertInterruptedConversion(array $update): void
    {
        [$table, $data, $where] = $update;
        $this->assertSame('magebit_mcp_job_item', $table);
        $this->assertSame([
            'status' => 'error',
            'error_code' => 'INTERRUPTED',
            'message' => 'The worker stopped while this item was running; it may already have been applied. '
                . 'Check it and send it again in a new call if needed.',
        ], $data);
        $this->assertSame(['job_id = ?' => 'j1', 'status = ?' => 'running'], $where);
    }

    /**
     * @param list<string> $order
     * @return void
     */
    private function givenOrderedTransaction(array &$order): void
    {
        $this->connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$order): AdapterInterface {
                $order[] = 'begin';
                return $this->connection;
            });
        $this->connection->expects($this->once())->method('commit')
            ->willReturnCallback(function () use (&$order): AdapterInterface {
                $order[] = 'commit';
                return $this->connection;
            });
    }
}
