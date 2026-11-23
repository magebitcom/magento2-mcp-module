<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Tool\System\Job\Cancel;
use Magebit\Mcp\Tool\System\Job\Get;
use Magebit\Mcp\Tool\System\Job\ListJobs;
use Magebit\Mcp\Tool\System\Job\Resume;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * A literal grant only reaches the jobs of the tools it was granted; a wildcard grant reaches all the admin's jobs.
 */
class LiteralGrantTest extends JobToolTestCase
{
    private const OUTSIDE_GRANT = 'This connection was not granted catalog_category_bulk_update, '
        . 'so it cannot read or change this job.';

    /**
     * @dataProvider jobTools
     * @param string $toolKey
     * @param string $state
     */
    public function testLiteralGrantIsRefusedAJobOfAToolOutsideItsGrant(string $toolKey, string $state): void
    {
        $this->grant([self::TOOL, 'cms.page.update']);
        $this->jobs->method('getByJobId')->willReturn(
            $this->job($state, self::ADMIN_ID, ['tool' => self::OTHER_TOOL])
        );
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->jobs->expects(self::never())->method('requestCancel');
        $this->jobs->expects(self::never())->method('cancelIfQueued');
        $this->jobs->expects(self::never())->method('requeue');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(self::OUTSIDE_GRANT);
        $this->jobTool($toolKey)->execute(['job_id' => 'j1']);
    }

    /**
     * @dataProvider jobTools
     * @param string $toolKey
     * @param string $state
     */
    public function testLiteralGrantReachesAJobOfAToolInItsGrant(string $toolKey, string $state): void
    {
        $this->grant([self::TOOL]);
        $this->stubJobActions($state, self::TOOL);

        self::assertFalse($this->jobTool($toolKey)->execute(['job_id' => 'j1'])->isError());
    }

    /**
     * @dataProvider jobTools
     * @param string $toolKey
     * @param string $state
     */
    public function testWildcardGrantReachesAnyOwnJob(string $toolKey, string $state): void
    {
        $this->grant(null);
        $this->stubJobActions($state, self::OTHER_TOOL);

        self::assertFalse($this->jobTool($toolKey)->execute(['job_id' => 'j1'])->isError());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function jobTools(): array
    {
        return [
            'get' => ['get', 'running'],
            'cancel' => ['cancel', 'running'],
            'resume' => ['resume', 'failed'],
        ];
    }

    public function testListFiltersByTheRoleAndTheLiteralGrant(): void
    {
        $this->grant([self::TOOL, 'cms.page.update']);
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, null, null, 1, 50, [self::TOOL])
            ->willReturn(['items' => [], 'total' => 0]);

        $this->listTool()->execute([]);
    }

    public function testListWithNoGrantedJobToolsPassesAnEmptyFilter(): void
    {
        $this->grant(['cms.page.update']);
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, null, null, 1, 50, [])
            ->willReturn(['items' => [], 'total' => 0]);

        $this->listTool()->execute([]);
    }

    public function testListForWildcardGrantFiltersByTheRoleOnly(): void
    {
        $this->grant(null);
        $this->jobs->expects(self::once())->method('listForAdmin')
            ->with(self::ADMIN_ID, null, null, 1, 50, [self::TOOL, self::OTHER_TOOL])
            ->willReturn(['items' => [], 'total' => 0]);

        $this->listTool()->execute([]);
    }

    public function testRoleIsStillCheckedForAGrantedTool(): void
    {
        $this->grant([self::TOOL]);
        $this->denied['Magento_Catalog::products'] = true;
        $this->jobs->method('getByJobId')->willReturn($this->job('running'));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Your admin role no longer allows catalog_product_bulk_update');
        $this->jobTool('get')->execute(['job_id' => 'j1']);
    }

    /**
     * @param string $state
     * @param string $toolName
     * @return void
     */
    private function stubJobActions(string $state, string $toolName): void
    {
        // A resumable job still has its stored input.
        $this->jobs->method('getByJobId')->willReturn(
            $this->job($state, self::ADMIN_ID, ['tool' => $toolName, 'items' => [['sku' => 'A']]])
        );
        $this->jobs->method('cancelIfQueued')->willReturn(false);
        $this->jobs->method('requeue')->willReturn(true);
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
    }

    /**
     * @param string $key
     * @return ToolInterface
     */
    private function jobTool(string $key): ToolInterface
    {
        return match ($key) {
            'get' => new Get($this->contextProvider, $this->access, $this->jobs),
            'cancel' => new Cancel(
                $this->contextProvider,
                $this->access,
                $this->jobs,
                new JobItemKeys($this->registry)
            ),
            default => new Resume(
                $this->contextProvider,
                $this->access,
                $this->jobs,
                $this->createMock(PublisherInterface::class),
                $this->createMock(LoggerInterface::class)
            ),
        };
    }

    /**
     * @return ListJobs
     */
    private function listTool(): ListJobs
    {
        return new ListJobs($this->contextProvider, $this->access, $this->jobs, $this->registry);
    }
}
