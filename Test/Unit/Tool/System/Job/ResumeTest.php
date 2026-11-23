<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Job\JobScheduler;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Job\Resume;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class ResumeTest extends JobToolTestCase
{
    private const ITEMS = [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']];

    /** @var PublisherInterface&MockObject */
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = $this->createMock(PublisherInterface::class);
    }

    private function tool(): Resume
    {
        return new Resume(
            $this->contextProvider,
            $this->access,
            $this->jobs,
            $this->publisher,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testMetadata(): void
    {
        $tool = $this->tool();
        self::assertSame('system.job.resume', $tool->getName());
        self::assertSame(WriteMode::WRITE, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
    }

    /**
     * @dataProvider refusedStates
     */
    public function testOnlyFailedOrCancelled(string $state): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job($state));
        $this->jobs->expects(self::never())->method('requeue');
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Only failed or cancelled jobs can be resumed.');
        $this->tool()->execute(['job_id' => 'j1']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedStates(): array
    {
        return ['queued' => ['queued'], 'running' => ['running'], 'completed' => ['completed']];
    }

    public function testRepublishes(): void
    {
        $this->jobs->method('getByJobId')->willReturn(
            $this->job('failed', self::ADMIN_ID, ['next' => 4, 'items' => self::ITEMS])
        );
        $this->jobs->expects(self::once())->method('requeue')->with('j1')->willReturn(true);
        $this->publisher->expects(self::once())->method('publish')->with(JobScheduler::TOPIC, 'j1');

        $result = $this->tool()->execute(['job_id' => 'j1']);

        self::assertSame(
            'Job j1 was queued again and continues from item 4; follow it with system_job_get.',
            $this->text($result)
        );
    }

    /**
     * A legacy row without stored input: the only case that says the input is gone.
     */
    public function testInputGone(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('cancelled'));
        $this->jobs->expects(self::never())->method('requeue');
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Job j1 cannot be resumed because its input is no longer stored; '
            . 'send the remaining items in a new call.'
        );
        $this->tool()->execute(['job_id' => 'j1']);
    }

    /**
     * Review lap 1 #4: a concurrent resume (or a worker) won the conditional requeue. The input is still stored,
     * so the agent is sent to follow the job, not to send the items again.
     *
     * @dataProvider raceProvider
     */
    public function testLostRequeueReportsTheRace(?string $stateAfter, string $expected): void
    {
        $reads = 0;
        $this->jobs->method('getByJobId')->willReturnCallback(
            function () use (&$reads, $stateAfter): JobInterface {
                if (++$reads === 1) {
                    return $this->job('failed', self::ADMIN_ID, ['items' => self::ITEMS]);
                }
                if ($stateAfter === null) {
                    throw NoSuchEntityException::singleField('job_id', 'j1');
                }
                return $this->job($stateAfter, self::ADMIN_ID, ['items' => self::ITEMS]);
            }
        );
        $this->jobs->expects(self::once())->method('requeue')->with('j1')->willReturn(false);
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expected);
        $this->tool()->execute(['job_id' => 'j1']);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function raceProvider(): array
    {
        $again = 'Job j1 is already queued or running again; follow it with system_job_get.';
        $changed = 'Job j1 changed state while it was being resumed; check it with system_job_get.';
        return [
            'queued by a concurrent resume' => ['queued', $again],
            'claimed by a worker' => ['running', $again],
            'completed since' => ['completed', $changed],
            'purged since' => [null, $changed],
        ];
    }

    public function testPublishFailureFailsTheJobAndThrows(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('failed', self::ADMIN_ID, ['items' => self::ITEMS]));
        $this->jobs->method('requeue')->willReturn(true);
        $this->publisher->method('publish')->willThrowException(new RuntimeException('amqp down'));
        $this->jobs->expects(self::once())->method('failQueued')->with('j1', self::anything());

        $this->expectException(LocalizedException::class);
        $this->tool()->execute(['job_id' => 'j1']);
    }

    public function testOtherAdminsJobNotFound(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('failed', 99));
        $this->jobs->expects(self::never())->method('requeue');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Job j1 was not found.');
        $this->tool()->execute(['job_id' => 'j1']);
    }
}
