<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Cron;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Cron\MarkStaleJobs;
use Magebit\Mcp\Model\Job\JobRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MarkStaleJobsTest extends TestCase
{
    private const ERROR = 'Worker stopped (no heartbeat for 10 minutes); resume with system_job_resume.';

    public function testStaleRunningMarkedFailed(): void
    {
        $repo = $this->createMock(JobRepository::class);
        $repo->expects(self::once())->method('findStaleRunning')->with(600)
            ->willReturn([$this->job('j1', 'r1')]);
        $repo->expects(self::once())->method('failStale')
            ->with('j1', 'r1', 600, self::ERROR)
            ->willReturn(true);
        $repo->expects(self::never())->method('finish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('MCP marked 1 stale job(s) as failed.');

        (new MarkStaleJobs($repo, $logger))->execute();
    }

    /**
     * PR #48 review: a heartbeat that arrives between the lookup and the update keeps the run alive; the job is
     * not counted as marked and nothing is logged as an error.
     */
    public function testHeartbeatBetweenLookupAndUpdateLeavesTheRunAlone(): void
    {
        $repo = $this->createMock(JobRepository::class);
        $repo->method('findStaleRunning')->willReturn([$this->job('j1', 'r1')]);
        $repo->expects(self::once())->method('failStale')->with('j1', 'r1', 600, self::ERROR)->willReturn(false);
        $repo->expects(self::never())->method('finish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('info');
        $logger->expects(self::once())->method('debug')->with(self::stringContains('j1'));

        (new MarkStaleJobs($repo, $logger))->execute();
    }

    public function testJobWithoutRunIdIsSkippedAndOthersContinue(): void
    {
        $repo = $this->createMock(JobRepository::class);
        $repo->method('findStaleRunning')->willReturn([
            $this->job('old', null),
            $this->job('boom', 'rb'),
            $this->job('ok', 'ro'),
        ]);
        $calls = [];
        $repo->method('failStale')->willReturnCallback(
            function (string $jobId) use (&$calls): bool {
                $calls[] = $jobId;
                if ($jobId === 'boom') {
                    throw new RuntimeException('db gone');
                }
                return true;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::once())->method('error');

        (new MarkStaleJobs($repo, $logger))->execute();

        self::assertSame(['boom', 'ok'], $calls);
    }

    private function job(string $jobId, ?string $runId): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn($jobId);
        $job->method('getRunId')->willReturn($runId);
        return $job;
    }
}
