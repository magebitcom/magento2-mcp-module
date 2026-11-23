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
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Job\ConsumerRunnerProbe;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobScheduler;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JobSchedulerTest extends TestCase
{
    /**
     * @phpstan-var JobRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobRepository&MockObject $jobs;

    /**
     * @phpstan-var PublisherInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private PublisherInterface&MockObject $publisher;

    /**
     * @phpstan-var ConsumerRunnerProbe&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ConsumerRunnerProbe&MockObject $probe;

    private JobScheduler $scheduler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->jobs = $this->createMock(JobRepository::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->probe = $this->createMock(ConsumerRunnerProbe::class);
        $this->scheduler = new JobScheduler(
            $this->jobs,
            $this->publisher,
            $this->probe,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return void
     */
    public function testScheduleCreatesJobAndPublishesJobId(): void
    {
        $this->probe->method('isStartedByCron')->willReturn(true);
        $this->jobs->expects($this->once())->method('create')
            ->with('catalog.product.update', 5, 9, 4, true, [['sku' => 'a'], ['sku' => 'b']])
            ->willReturn($this->job('job-1'));
        $this->publisher->expects($this->once())->method('publish')->with('magebit.mcp.job.run', 'job-1');

        $result = $this->scheduler->schedule(
            $this->tool(),
            [3 => ['sku' => 'a'], 7 => ['sku' => 'b']],
            true,
            $this->context(9)
        );

        $this->assertSame(
            [
                'job_id' => 'job-1',
                'state' => 'queued',
                'total' => 2,
                'sync_cap' => 50,
                'message' => 'Started as background job job-1; follow it with system_job_get.',
            ],
            $result
        );
    }

    /**
     * @return void
     */
    public function testMessageAddsWorkerWarningWhenProbeFalse(): void
    {
        $this->probe->method('isStartedByCron')->willReturn(false);
        $this->jobs->method('create')->willReturn($this->job('job-2'));

        $result = $this->scheduler->schedule($this->tool(), [['sku' => 'a']], false, $this->context(9));

        $this->assertSame(
            'Started as background job job-2; follow it with system_job_get. This store does not start queue '
            . 'workers from cron, so the job runs only if a worker for magebit_mcp_job_runner is started separately.',
            $result['message']
        );
    }

    /**
     * @return void
     */
    public function testPublishFailureFailsTheQueuedJobAndRethrows(): void
    {
        $this->jobs->method('create')->willReturn($this->job('job-3'));
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('broker down'));
        $this->jobs->expects($this->once())->method('failQueued')->with('job-3', $this->isType('string'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The background job could not be queued; try again or send fewer items.');
        $this->scheduler->schedule($this->tool(), [['sku' => 'a']], false, $this->context(9));
    }

    /**
     * @return void
     */
    public function testTokenWithoutIdIsRejectedBeforeAnythingIsStored(): void
    {
        $this->jobs->expects($this->never())->method('create');
        $this->publisher->expects($this->never())->method('publish');

        $this->expectException(LocalizedException::class);
        $this->scheduler->schedule($this->tool(), [['sku' => 'a']], false, $this->context(null));
    }

    /**
     * @return BulkToolInterface&MockObject
     */
    private function tool(): BulkToolInterface
    {
        $tool = $this->createMock(BulkToolInterface::class);
        $tool->method('getName')->willReturn('catalog.product.update');
        $tool->method('getSyncCap')->willReturn(50);
        return $tool;
    }

    /**
     * @param int|null $tokenId
     * @return AuthenticatedContext
     */
    private function context(?int $tokenId): AuthenticatedContext
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getId')->willReturn($tokenId);
        $token->method('getOAuthClientId')->willReturn(4);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('5');
        return new AuthenticatedContext($token, $user);
    }

    /**
     * @param string $jobId
     * @return JobInterface&MockObject
     */
    private function job(string $jobId): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn($jobId);
        return $job;
    }
}
