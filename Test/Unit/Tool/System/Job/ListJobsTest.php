<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Job\ListJobs;

class ListJobsTest extends JobToolTestCase
{
    private function tool(): ListJobs
    {
        return new ListJobs($this->contextProvider, $this->access, $this->jobs, $this->registry);
    }

    public function testMetadata(): void
    {
        $tool = $this->tool();
        self::assertSame('system.job.list', $tool->getName());
        self::assertSame(WriteMode::READ, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
        $state = $this->property($tool->getInputSchema(), 'state');
        self::assertIsArray($state['enum']);
        self::assertCount(5, $state['enum']);
    }

    public function testFiltersByRoleAccess(): void
    {
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, 'running', null, 2, 10, [self::TOOL, self::OTHER_TOOL])
            ->willReturn(['items' => [$this->job('running')], 'total' => 11]);

        $payload = $this->decode(
            $this->tool()->execute(['state' => 'running', 'page' => 2, 'page_size' => 10])
        );

        self::assertSame(11, $payload['total']);
        self::assertSame(2, $payload['page']);
        self::assertSame(10, $payload['page_size']);
        $jobs = $payload['jobs'];
        self::assertIsArray($jobs);
        self::assertIsArray($jobs[0]);
        self::assertSame('catalog_product_bulk_update', $jobs[0]['tool']);
        self::assertArrayNotHasKey('items', $jobs[0]);
    }

    public function testToolWhoseRoleAccessWasLostIsNotPassedToTheRepository(): void
    {
        $this->denied['Magento_Catalog::products'] = true;
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, null, null, 1, 50, [])
            ->willReturn(['items' => [], 'total' => 0]);

        $payload = $this->decode($this->tool()->execute([]));

        self::assertSame([], $payload['jobs']);
        self::assertSame(0, $payload['total']);
    }

    public function testToolNameAcceptsWireNameAndResolvesCanonical(): void
    {
        $this->registry = $this->createMock(\Magebit\Mcp\Api\ToolRegistryInterface::class);
        $this->registry->method('getCanonicalName')->with('catalog_product_bulk_update')->willReturn(self::TOOL);
        $this->registry->method('all')->willReturn([]);
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, null, self::TOOL, 1, 50, self::anything())
            ->willReturn(['items' => [], 'total' => 0]);

        $this->tool()->execute(['tool_name' => 'catalog_product_bulk_update']);
    }

    /**
     * Follow-up A: the list splits a job's stored text like system_job_get does, through the same helper.
     */
    public function testStoredTextIsANoticeOnEndedJobsAndAnErrorOnFailedOnes(): void
    {
        $notice = "The items were processed, but the tool's follow-up step after the batch failed; see the store log.";
        $this->jobs->method('listForAdmin')->willReturn([
            'items' => [
                $this->job('completed', self::ADMIN_ID, ['error' => $notice]),
                $this->job('cancelled', self::ADMIN_ID, ['error' => $notice]),
                $this->job('failed', self::ADMIN_ID, ['error' => 'Snapshot failed: locked.']),
                $this->job('running'),
            ],
            'total' => 4,
        ]);

        $payload = $this->decode($this->tool()->execute([]));

        $jobs = $payload['jobs'];
        self::assertIsArray($jobs);
        $split = array_map(
            static fn (array $row): array => [$row['error'], $row['notice'] ?? 'absent'],
            $jobs
        );
        self::assertSame([
            [null, $notice],
            [null, $notice],
            ['Snapshot failed: locked.', 'absent'],
            [null, 'absent'],
        ], $split);
    }
}
