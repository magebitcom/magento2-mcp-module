<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Job\Get;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class GetTest extends JobToolTestCase
{
    private function tool(): Get
    {
        return new Get($this->contextProvider, $this->access, $this->jobs);
    }

    public function testMetadata(): void
    {
        $tool = $this->tool();
        self::assertSame('system.job.get', $tool->getName());
        self::assertSame('Magebit_Mcp::tools', $tool->getAclResource());
        self::assertSame(WriteMode::READ, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
        $schema = $tool->getInputSchema();
        self::assertSame(['job_id'], $schema['required']);
        self::assertSame(200, $this->property($schema, 'page_size')['maximum']);
    }

    public function testNoticeAfterFiveMinutesQueued(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('queued'));
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->jobs->method('secondsSinceQueued')->willReturn(301);

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1']));

        self::assertSame(Get::NOTICE, $payload['notice']);
        self::assertStringStartsWith('No worker has picked this job up for over 5 minutes.', $payload['notice']);
        self::assertSame('catalog_product_bulk_update', $payload['tool']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endedWithNoticeProvider(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    /**
     * Review lap 1 #5: a completed or cancelled job's stored text is the after-batch notice, not an error.
     *
     * @dataProvider endedWithNoticeProvider
     */
    public function testStoredTextOnAnEndedJobShowsAsNotice(string $state): void
    {
        $text = "The items were processed, but the tool's follow-up step after the batch failed; see the store log.";
        $this->jobs->method('getByJobId')->willReturn($this->job($state, self::ADMIN_ID, ['error' => $text]));
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->jobs->expects(self::never())->method('secondsSinceQueued');

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1']));

        self::assertSame($text, $payload['notice']);
        self::assertNull($payload['error']);
    }

    public function testStoredTextOnAFailedJobStaysTheError(): void
    {
        $this->jobs->method('getByJobId')->willReturn(
            $this->job('failed', self::ADMIN_ID, ['error' => 'Snapshot failed: locked.'])
        );
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1']));

        self::assertSame('Snapshot failed: locked.', $payload['error']);
        self::assertArrayNotHasKey('notice', $payload);
    }

    public function testNoNoticeWhenQueuedForLessThanFiveMinutes(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('queued'));
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->jobs->method('secondsSinceQueued')->willReturn(300);

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1']));

        self::assertArrayNotHasKey('notice', $payload);
    }

    /**
     * Review L1: a job resumed long after it was created is measured from its resume, not its creation.
     *
     * @return void
     */
    public function testNoNoticeForAJobResumedRecently(): void
    {
        $resumed = $this->job('queued', self::ADMIN_ID, ['next' => 2]);
        $this->jobs->method('getByJobId')->willReturn($resumed);
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->jobs->expects(self::once())->method('secondsSinceQueued')->with('j1')->willReturn(30);

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1']));

        self::assertArrayNotHasKey('notice', $payload);
        self::assertSame(2, $payload['next_index']);
    }

    public function testNoNoticeWhenRunning(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('running'));
        $this->jobs->method('getItems')->willReturn(['items' => [['index' => 0]], 'total' => 1]);
        $this->jobs->expects(self::never())->method('secondsSinceQueued');

        $payload = $this->decode($this->tool()->execute(['job_id' => 'j1', 'page_size' => 10]));

        self::assertArrayNotHasKey('notice', $payload);
        self::assertSame(1, $payload['items_total']);
        self::assertSame(10, $payload['page_size']);
        self::assertSame(1, $payload['page']);
    }

    public function testOtherAdminsJobNotFound(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('running', 99));

        try {
            $this->tool()->execute(['job_id' => 'j1']);
            self::fail('Expected not found.');
        } catch (LocalizedException $e) {
            self::assertSame('Job j1 was not found.', (string) $e->getMessage());
        }
    }

    public function testUnknownIdAnswersTheSameAsAnotherAdminsJob(): void
    {
        $this->jobs->method('getByJobId')->willThrowException(new NoSuchEntityException());

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Job j1 was not found.');
        $this->tool()->execute(['job_id' => 'j1']);
    }

    public function testRoleLostForbidden(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('completed'));
        $this->denied['Magento_Catalog::products'] = true;

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(
            'Your admin role no longer allows catalog_product_bulk_update, so this job cannot be read or changed.'
        );
        $this->tool()->execute(['job_id' => 'j1']);
    }

    public function testToolNoLongerRegisteredForbidden(): void
    {
        $other = $this->createMock(JobInterface::class);
        $other->method('getJobId')->willReturn('j1');
        $other->method('getToolName')->willReturn('gone.tool');
        $other->method('getAdminUserId')->willReturn(self::ADMIN_ID);
        $this->jobs->method('getByJobId')->willReturn($other);

        $this->expectException(ForbiddenException::class);
        $this->tool()->execute(['job_id' => 'j1']);
    }
}
