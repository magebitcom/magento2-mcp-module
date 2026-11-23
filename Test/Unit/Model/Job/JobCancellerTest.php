<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\Job\JobConnection;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JobCancellerTest extends TestCase
{
    /**
     * @phpstan-var JobRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobRepository&MockObject $repo;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->repo = $this->createMock(JobRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testOAuthTokenCancelsByAdminAndClient(): void
    {
        $connections = [JobConnection::oauth(7, 3)];
        $this->repo->expects(self::once())->method('openJobsForConnections')->with($connections)
            ->willReturn(['r1' => 'running']);
        $this->repo->expects(self::once())->method('cancelOpenForConnections')->with($connections)->willReturn(2);

        self::assertSame(2, $this->canceller()->cancelForRevokedToken($this->token(7, 3, 11)));
    }

    public function testPersonalTokenCancelsByTokenId(): void
    {
        $connections = [JobConnection::personal(11)];
        $this->repo->expects(self::once())->method('openJobsForConnections')->with($connections)
            ->willReturn(['r1' => 'running']);
        $this->repo->expects(self::once())->method('cancelOpenForConnections')->with($connections)->willReturn(1);

        self::assertSame(1, $this->canceller()->cancelForRevokedToken($this->token(7, null, 11)));
    }

    public function testATokenWithoutAnIdCancelsNothing(): void
    {
        $this->repo->expects(self::never())->method('openJobsForConnections');

        self::assertSame(0, $this->canceller()->cancelForRevokedToken($this->token(7, null, null)));
    }

    public function testFailureIsLoggedAndReturnsZero(): void
    {
        $this->repo->method('openJobsForConnections')->willReturn(['q1' => 'queued']);
        $this->repo->method('cancelOpenForConnections')->willThrowException(new RuntimeException('db'));
        $this->repo->expects(self::never())->method('cancelledJobsForCleanup');
        $this->logger->expects(self::once())->method('error');

        self::assertSame(0, $this->canceller()->cancelForRevokedToken($this->token(7, null, 11)));
    }

    /**
     * PR #48 review round 5 (S2): with no open job a revoke costs one lookup and nothing else.
     *
     * @return void
     */
    public function testNoOpenJobsIsASingleLookup(): void
    {
        $this->repo->expects(self::once())->method('openJobsForConnections')->willReturn([]);
        $this->repo->expects(self::never())->method('cancelOpenForConnections');
        $this->repo->expects(self::never())->method('cancelledJobsForCleanup');

        self::assertSame(0, $this->canceller()->cancelForConnections([
            JobConnection::oauth(7, 3),
            JobConnection::personal(11),
        ]));
    }

    /**
     * PR #48 review round 5 (S2): a mass action's N tokens over M connections cost ceil(M / 50) lookups and as many
     * cancel UPDATEs, never one per token; duplicate connections are matched once.
     *
     * @return void
     */
    public function testConnectionsAreCancelledInBatchesOf50(): void
    {
        $connections = [];
        for ($i = 1; $i <= 60; $i++) {
            // Two tokens of the same OAuth connection (a rotated one and its successor), counted once.
            $connections[] = JobConnection::oauth($i, 3);
            $connections[] = JobConnection::oauth($i, 3);
            $connections[] = JobConnection::personal(1000 + $i);
        }
        $lookups = [];
        $this->repo->expects(self::exactly(3))->method('openJobsForConnections')->willReturnCallback(
            function (array $page) use (&$lookups): array {
                $lookups[] = $page;
                return ['q' . count($lookups) => 'queued', 'r' . count($lookups) => 'running'];
            }
        );
        $cancels = [];
        $this->repo->expects(self::exactly(3))->method('cancelOpenForConnections')->willReturnCallback(
            function (array $page) use (&$cancels): int {
                $cancels[] = $page;
                return 2;
            }
        );
        $cleanup = [];
        $this->repo->expects(self::once())->method('cancelledJobsForCleanup')->willReturnCallback(
            function (array $page) use (&$cleanup): array {
                $cleanup = $page;
                return [];
            }
        );

        self::assertSame(6, $this->canceller()->cancelForConnections($connections));
        self::assertSame([50, 50, 20], array_map('count', $lookups));
        self::assertSame($lookups, $cancels);
        $keys = array_map(fn (JobConnection $c): string => $c->key(), array_merge(...$lookups));
        self::assertCount(120, array_unique($keys));
        // Only the jobs that were queued get their items marked; a running one is marked by its worker.
        self::assertSame(['q1', 'q2', 'q3'], $cleanup);
    }

    /**
     * A batch whose queries fail is logged and skipped; the next batch still runs and its count is kept.
     *
     * @return void
     */
    public function testAFailedBatchIsLoggedAndTheNextBatchRuns(): void
    {
        $connections = [];
        for ($i = 1; $i <= 51; $i++) {
            $connections[] = JobConnection::personal($i);
        }
        $this->repo->method('openJobsForConnections')->willReturnCallback(
            function (array $page): array {
                if (count($page) === 50) {
                    throw new RuntimeException('db');
                }
                return ['q51' => 'queued'];
            }
        );
        $this->repo->expects(self::once())->method('cancelOpenForConnections')->willReturn(1);
        $this->repo->expects(self::once())->method('cancelledJobsForCleanup')->with(['q51'])->willReturn([]);
        $this->logger->expects(self::once())->method('error');

        self::assertSame(1, $this->canceller()->cancelForConnections($connections));
    }

    public function testNoConnectionsRunsNoQuery(): void
    {
        $this->repo->expects(self::never())->method('openJobsForConnections');

        self::assertSame(0, $this->canceller()->cancelForConnections([]));
    }

    public function testConnectionOfAToken(): void
    {
        self::assertEquals(JobConnection::oauth(7, 3), JobConnection::fromToken($this->token(7, 3, 11)));
        self::assertEquals(JobConnection::personal(11), JobConnection::fromToken($this->token(7, null, 11)));
        self::assertNull(JobConnection::fromToken($this->token(7, null, null)));
        self::assertNotSame(JobConnection::oauth(7, 3)->key(), JobConnection::personal(7)->key());
        self::assertNotSame(JobConnection::oauth(7, 3)->key(), JobConnection::oauth(3, 7)->key());
    }

    public function testClientCancelsByClient(): void
    {
        $this->repo->method('queuedJobIdsForClient')->with(3)->willReturn([]);
        $this->repo->expects(self::once())->method('cancelOpenForClient')->with(3)->willReturn(4);

        self::assertSame(4, $this->canceller()->cancelForClient(3));
    }

    public function testClientFailureIsLoggedAndReturnsZero(): void
    {
        $this->repo->method('cancelOpenForClient')->willThrowException(new RuntimeException('db'));
        $this->logger->expects(self::once())->method('error');

        self::assertSame(0, $this->canceller()->cancelForClient(3));
    }

    /**
     * Review L2: a queued job cancelled by a revoke gets the same skipped/CANCELLED rows as system_job_cancel.
     *
     * @return void
     */
    public function testRevokeMarksTheItemsOfCancelledQueuedJobs(): void
    {
        $calls = [];
        $this->repo->method('openJobsForConnections')->willReturnCallback(
            function () use (&$calls): array {
                $calls[] = 'collect';
                return ['q1' => 'queued'];
            }
        );
        $this->repo->method('cancelOpenForConnections')->willReturnCallback(
            function () use (&$calls): int {
                $calls[] = 'cancel';
                return 2;
            }
        );
        $this->repo->method('cancelledJobsForCleanup')->with(['q1'])
            ->willReturn([$this->job('q1', 'cancelled', 1, [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']])]);
        $this->repo->expects(self::once())->method('markRemainingCancelled')
            ->with('q1', 1, [1 => 'B', 2 => 'C'])
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'mark';
            });

        self::assertSame(2, $this->canceller()->cancelForRevokedToken($this->token(7, null, 11)));
        self::assertSame(['collect', 'cancel', 'mark'], $calls);
    }

    /**
     * A queued job a worker claimed in between is only flagged; the runner marks its items when it stops.
     *
     * @return void
     */
    public function testClaimedInBetweenIsNotMarked(): void
    {
        $this->repo->method('queuedJobIdsForClient')->willReturn(['q1', 'q2']);
        $this->repo->method('cancelOpenForClient')->willReturn(2);
        // The read returns cancelled jobs only; a job claimed in between is skipped even if it were returned.
        $this->repo->method('cancelledJobsForCleanup')->with(['q1', 'q2'])->willReturn([
            $this->job('q1', 'running', 0, [['sku' => 'A']]),
            $this->job('q2', 'cancelled', 0, [['sku' => 'X']]),
        ]);
        $this->repo->expects(self::once())->method('markRemainingCancelled')->with('q2', 0, [0 => 'X']);

        self::assertSame(2, $this->canceller()->cancelForClient(3));
    }

    public function testMarkingFailureKeepsTheCount(): void
    {
        $this->repo->method('queuedJobIdsForClient')->willReturn(['q1']);
        $this->repo->method('cancelOpenForClient')->willReturn(1);
        $this->repo->method('cancelledJobsForCleanup')->willReturn([$this->job('q1', 'cancelled', 0, [])]);
        $this->repo->method('markRemainingCancelled')->willThrowException(new RuntimeException('db'));
        $this->logger->expects(self::once())->method('error');

        self::assertSame(1, $this->canceller()->cancelForClient(3));
    }

    /**
     * PR #48 review round 4 (P2): the queued jobs are read back in pages of 50, one query per page, never one
     * full read per job; every cancelled job still gets its rows. A page holds each job's stored input, so it is
     * kept small enough that a page of full-size inputs stays a bounded amount of memory in the admin request.
     *
     * @return void
     */
    public function testCancelledJobsAreReadInPagesOf50(): void
    {
        $ids = [];
        for ($i = 0; $i < 120; $i++) {
            $ids[] = 'q' . $i;
        }
        $this->repo->method('queuedJobIdsForClient')->willReturn($ids);
        $this->repo->method('cancelOpenForClient')->willReturn(120);
        $this->repo->expects(self::never())->method('getByJobId');
        $pages = [];
        $this->repo->expects(self::exactly(3))->method('cancelledJobsForCleanup')->willReturnCallback(
            function (array $page) use (&$pages): array {
                $pages[] = $page;
                return array_map(fn (string $id): JobInterface => $this->job($id, 'cancelled', 0, []), $page);
            }
        );
        $marked = [];
        $this->repo->expects(self::exactly(120))->method('markRemainingCancelled')->willReturnCallback(
            function (string $jobId) use (&$marked): void {
                $marked[] = $jobId;
            }
        );

        self::assertSame(120, $this->canceller()->cancelForClient(3));
        self::assertSame([50, 50, 20], array_map('count', $pages));
        self::assertSame($ids, array_merge(...$pages));
        self::assertSame($ids, $marked);
    }

    /**
     * A page that cannot be read is logged and skipped; later pages are still cleaned up and the count is kept.
     *
     * @return void
     */
    public function testAFailedPageReadIsLoggedAndTheNextPageRuns(): void
    {
        $ids = [];
        for ($i = 0; $i < 51; $i++) {
            $ids[] = 'q' . $i;
        }
        $this->repo->method('openJobsForConnections')->willReturn(array_fill_keys($ids, 'queued'));
        $this->repo->method('cancelOpenForConnections')->willReturn(51);
        $this->repo->method('cancelledJobsForCleanup')->willReturnCallback(
            function (array $page): array {
                if (count($page) === 50) {
                    throw new RuntimeException('db');
                }
                return [$this->job('q50', 'cancelled', 0, [])];
            }
        );
        $this->logger->expects(self::once())->method('error');
        $this->repo->expects(self::once())->method('markRemainingCancelled')->with('q50', 0, []);

        self::assertSame(51, $this->canceller()->cancelForRevokedToken($this->token(7, null, 11)));
    }

    /**
     * @return JobCanceller
     */
    private function canceller(): JobCanceller
    {
        $bulk = $this->createMock(BulkToolInterface::class);
        $bulk->method('getItemKeyField')->willReturn('sku');
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('getBulkTool')->willReturn($bulk);
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->willReturn($adapter);

        return new JobCanceller($this->repo, $this->logger, new JobItemKeys($registry));
    }

    /**
     * @param string $jobId
     * @param string $state
     * @param int $nextIndex
     * @param array<int, mixed> $items
     * @return JobInterface
     */
    private function job(string $jobId, string $state, int $nextIndex, array $items): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn($jobId);
        $job->method('getToolName')->willReturn('catalog.product.bulk_update');
        $job->method('getState')->willReturn($state);
        $job->method('getNextIndex')->willReturn($nextIndex);
        $job->method('getItems')->willReturn($items);
        return $job;
    }

    private function token(int $adminId, ?int $clientId, ?int $id): Token
    {
        $token = $this->createMock(Token::class);
        $token->method('getAdminUserId')->willReturn($adminId);
        $token->method('getOAuthClientId')->willReturn($clientId);
        $token->method('getId')->willReturn($id);
        return $token;
    }
}
