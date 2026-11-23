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
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Tool\System\Job\Cancel;
use Magento\Framework\Exception\LocalizedException;

class CancelTest extends JobToolTestCase
{
    private function tool(): Cancel
    {
        return new Cancel($this->contextProvider, $this->access, $this->jobs, new JobItemKeys($this->registry));
    }

    public function testMetadata(): void
    {
        $tool = $this->tool();
        self::assertSame('system.job.cancel', $tool->getName());
        self::assertSame(WriteMode::WRITE, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
        self::assertSame('Magebit_Mcp::tools', $tool->getAclResource());
    }

    public function testQueuedCancelledAtOnce(): void
    {
        $this->jobs->method('getByJobId')->willReturn(
            $this->job('queued', self::ADMIN_ID, ['next' => 0, 'items' => [['sku' => 'a'], ['sku' => 7], ['x' => 1]]])
        );
        $this->jobs->expects(self::once())->method('cancelIfQueued')->with('j1')->willReturn(true);
        $this->jobs->expects(self::never())->method('requestCancel');
        $this->jobs->expects(self::once())->method('markRemainingCancelled')->with('j1', 0, [0 => 'a', 1 => '7', 2 => null]);
        $this->bulkRegistry();

        $result = $this->tool()->execute(['job_id' => 'j1']);

        self::assertSame('Job j1 was cancelled before it started.', $this->text($result));
    }

    public function testQueuedFallsThroughToRunningWhenWorkerClaimedIt(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('queued'));
        $this->jobs->method('cancelIfQueued')->willReturn(false);
        $this->jobs->expects(self::never())->method('markRemainingCancelled');
        $this->jobs->expects(self::once())->method('requestCancel')->with('j1');

        $result = $this->tool()->execute(['job_id' => 'j1']);

        self::assertSame(
            'Job j1 will stop after the current item; follow it with system_job_get.',
            $this->text($result)
        );
    }

    public function testRunningGetsCancelRequested(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('running'));
        $this->jobs->expects(self::never())->method('cancelIfQueued');
        $this->jobs->expects(self::once())->method('requestCancel')->with('j1');

        $result = $this->tool()->execute(['job_id' => 'j1']);

        self::assertStringContainsString('will stop after the current item', $this->text($result));
    }

    /**
     * @dataProvider terminalStates
     */
    public function testTerminalStateRefused(string $state): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job($state));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Job j1 has already finished (' . $state . ').');
        $this->tool()->execute(['job_id' => 'j1']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function terminalStates(): array
    {
        return ['completed' => ['completed'], 'failed' => ['failed'], 'cancelled' => ['cancelled']];
    }

    public function testOtherAdminsJobNotFound(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('running', 99));
        $this->jobs->expects(self::never())->method('requestCancel');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Job j1 was not found.');
        $this->tool()->execute(['job_id' => 'j1']);
    }

    public function testRoleLostForbidden(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job('running'));
        $this->denied['Magebit_Mcp::tools'] = true;
        $this->jobs->expects(self::never())->method('requestCancel');

        $this->expectException(ForbiddenException::class);
        $this->tool()->execute(['job_id' => 'j1']);
    }

    private function bulkRegistry(): void
    {
        // Replace the registry mock's `get` with a bulk adapter whose key field is `sku`.
        $bulk = $this->createMock(BulkToolInterface::class);
        $bulk->method('getItemKeyField')->willReturn('sku');
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('getBulkTool')->willReturn($bulk);
        $adapter->method('getAclResource')->willReturn('Magebit_Mcp::tools');
        $adapter->method('getUnderlyingAclResource')->willReturn('Magento_Catalog::products');
        $registry = $this->createMock(\Magebit\Mcp\Api\ToolRegistryInterface::class);
        $registry->method('get')->willReturn($adapter);
        $registry->method('all')->willReturn([self::TOOL => $adapter]);
        $this->access = new \Magebit\Mcp\Model\Job\JobToolAccess(
            $this->jobs,
            $registry,
            $this->acl,
            $this->contextProvider
        );
        $this->registry = $registry;
    }
}
