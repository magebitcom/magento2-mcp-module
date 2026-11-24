<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\JobSchedulerInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\StructuredToolResultInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkExecutor;
use Magebit\Mcp\Model\Tool\Bulk\BulkSchemaFactory;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\ItemAuditCollector;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

interface BulkToolAdapterTestAvailableTool extends BulkToolInterface, AvailabilityAwareInterface
{
}

interface BulkToolAdapterTestAuditTool extends BulkToolInterface, BulkItemAuditInterface
{
}

class BulkToolAdapterTest extends TestCase
{
    /** @var BulkSchemaFactory&MockObject */
    private BulkSchemaFactory $schemaFactory;
    /** @var BulkExecutor&MockObject */
    private BulkExecutor $executor;
    /** @var JobSchedulerInterface&MockObject */
    private JobSchedulerInterface $scheduler;
    private AuthenticatedContextProvider $provider;
    /** @var User&MockObject */
    private User $admin;
    private AuthenticatedContext $context;

    protected function setUp(): void
    {
        $this->schemaFactory = $this->createMock(BulkSchemaFactory::class);
        $this->executor = $this->createMock(BulkExecutor::class);
        $this->scheduler = $this->createMock(JobSchedulerInterface::class);
        $this->admin = $this->createMock(User::class);
        $this->context = new AuthenticatedContext($this->createMock(TokenInterface::class), $this->admin);
        $this->provider = new AuthenticatedContextProvider();
        $this->provider->set($this->context);
    }

    /**
     * @param BulkToolInterface $tool
     * @return BulkToolAdapter
     */
    private function adapter(BulkToolInterface $tool): BulkToolAdapter
    {
        return new BulkToolAdapter(
            $tool,
            $this->schemaFactory,
            $this->executor,
            $this->scheduler,
            $this->provider
        );
    }

    /**
     * @param class-string<BulkToolInterface> $class
     * @return BulkToolInterface&MockObject
     */
    private function tool(string $class = BulkToolInterface::class): BulkToolInterface
    {
        $tool = $this->createMock($class);
        $tool->method('getName')->willReturn('catalog.product.bulk_update');
        $tool->method('getTitle')->willReturn('Bulk update');
        $tool->method('getDescription')->willReturn('inner description');
        $tool->method('getAclResource')->willReturn('Vendor_Mod::mcp_tool');
        $tool->method('getUnderlyingAclResource')->willReturn('Magento_Catalog::products');
        $tool->method('getWriteMode')->willReturn(WriteMode::WRITE);
        $tool->method('getConfirmationRequired')->willReturn(true);
        $tool->method('getSyncCap')->willReturn(2);
        $tool->method('getAsyncCeiling')->willReturn(10);
        return $tool;
    }

    /**
     * @param array<int, string> $statuses
     * @return BatchResult
     */
    private function batch(array $statuses): BatchResult
    {
        $batch = new BatchResult();
        foreach ($statuses as $i => $status) {
            $batch->add(
                $i,
                'k' . $i,
                $status === ItemResult::STATUS_ERROR ? ItemResult::error('x', 'boom') : ItemResult::ok($i)
            );
        }
        return $batch;
    }

    /**
     * @param ToolResultInterface $result
     * @return string
     */
    private function text(ToolResultInterface $result): string
    {
        $text = $result->getContent()[0]['text'] ?? null;
        self::assertIsString($text);
        return $text;
    }

    public function testAdapterDelegatesMetadataAndUnderlyingAcl(): void
    {
        $tool = $this->tool();
        $this->schemaFactory->method('inputSchema')->with($tool)->willReturn(['type' => 'object', 'x' => 1]);
        $this->schemaFactory->method('outputSchema')->willReturn(['type' => 'object', 'y' => 1]);
        $this->schemaFactory->method('description')->with($tool)->willReturn('factory description');
        $adapter = $this->adapter($tool);

        self::assertSame($tool, $adapter->getBulkTool());
        self::assertSame('catalog.product.bulk_update', $adapter->getName());
        self::assertSame('Bulk update', $adapter->getTitle());
        self::assertSame('factory description', $adapter->getDescription());
        self::assertSame('Vendor_Mod::mcp_tool', $adapter->getAclResource());
        self::assertSame('Magento_Catalog::products', $adapter->getUnderlyingAclResource());
        self::assertSame(WriteMode::WRITE, $adapter->getWriteMode());
        self::assertTrue($adapter->getConfirmationRequired());
        self::assertSame(['type' => 'object', 'x' => 1], $adapter->getInputSchema());
        self::assertSame(['type' => 'object', 'y' => 1], $adapter->getOutputSchema());
        self::assertTrue($adapter->isAsyncCapable());
        self::assertTrue($adapter->isAvailable());
        self::assertNotInstanceOf(ContextualAclAwareInterface::class, $adapter);
    }

    public function testNotAsyncCapableWhenCeilingEqualsCap(): void
    {
        $tool = $this->createMock(BulkToolInterface::class);
        $tool->method('getSyncCap')->willReturn(5);
        $tool->method('getAsyncCeiling')->willReturn(5);

        self::assertFalse($this->adapter($tool)->isAsyncCapable());
    }

    public function testAvailabilityDelegates(): void
    {
        $tool = $this->createMock(BulkToolAdapterTestAvailableTool::class);
        $tool->method('isAvailable')->willReturn(false);

        self::assertFalse($this->adapter($tool)->isAvailable());
    }

    public function testSyncCallReturnsEnvelopeTextAndStructured(): void
    {
        $tool = $this->tool();
        $items = [['sku' => 'a'], ['sku' => 'b']];
        $this->executor->expects(self::once())->method('run')
            ->with($tool, $items, true, $this->admin)
            ->willReturn($this->batch([ItemResult::STATUS_OK, ItemResult::STATUS_ERROR]));

        $result = $this->adapter($tool)->execute(['items' => ['x' => $items[0], 'y' => $items[1]], 'dry_run' => true]);

        self::assertInstanceOf(StructuredToolResultInterface::class, $result);
        self::assertFalse($result->isError());
        $structured = $result->getStructuredContent();
        self::assertIsArray($structured);
        self::assertTrue($structured['dry_run']);
        self::assertSame(2, ((array) $structured['summary'])['total'] ?? null);
        $text = $this->text($result);
        self::assertSame($structured, json_decode($text, true));
        self::assertStringContainsString("\n", $text);
        self::assertSame(
            ['total' => 2, 'ok' => 1, 'failed' => 1, 'skipped' => 0, 'item_count' => 2],
            $result->getAuditSummary()
        );
    }

    public function testAllFailedSetsIsError(): void
    {
        $tool = $this->tool();
        $this->executor->method('run')
            ->willReturn($this->batch([ItemResult::STATUS_ERROR, ItemResult::STATUS_ERROR]));

        $result = $this->adapter($tool)->execute(['items' => [['sku' => 'a'], ['sku' => 'b']]]);

        self::assertTrue($result->isError());
    }

    public function testAboveCapCallsScheduler(): void
    {
        $tool = $this->tool();
        $items = [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c']];
        $job = ['job_id' => 'j1', 'state' => 'queued', 'total' => 3, 'sync_cap' => 2, 'message' => 'queued'];
        $this->executor->expects(self::never())->method('run');
        $this->scheduler->expects(self::once())->method('schedule')
            ->with($tool, $items, false, $this->context)
            ->willReturn($job);

        $result = $this->adapter($tool)->execute(['items' => $items]);

        self::assertFalse($result->isError());
        self::assertInstanceOf(StructuredToolResultInterface::class, $result);
        self::assertSame($job, $result->getStructuredContent());
        self::assertSame($job, json_decode($this->text($result), true));
        self::assertSame(['job_id' => 'j1', 'item_count' => 3], $result->getAuditSummary());
    }

    public function testMissingContextThrows(): void
    {
        $this->provider = new AuthenticatedContextProvider();

        $this->expectException(LocalizedException::class);
        $this->adapter($this->tool())->execute(['items' => [['sku' => 'a']]]);
    }

    public function testAuditAwareToolAddsItemsToTheSyncAuditSummary(): void
    {
        $tool = $this->tool(BulkToolAdapterTestAuditTool::class);
        $this->executor->expects(self::once())->method('run')->willReturnCallback(
            function (
                BulkToolInterface $tool,
                array $items,
                bool $dryRun,
                User $admin,
                int $startIndex = 0,
                ?callable $afterItem = null,
                ?callable $shouldStop = null,
                ?callable $beforeAfterBatch = null,
                ?ItemAuditCollector $auditCollector = null
            ): BatchResult {
                self::assertNotNull($auditCollector);
                $auditCollector->begin();
                $auditCollector->add(0, 'k0', 'ok', ['previous' => 'a', 'value' => 'b']);
                return $this->batch([ItemResult::STATUS_OK, ItemResult::STATUS_OK]);
            }
        );

        $result = $this->adapter($tool)->execute(['items' => [['sku' => 'a'], ['sku' => 'b']]]);

        self::assertSame([
            'total' => 2,
            'ok' => 2,
            'failed' => 0,
            'skipped' => 0,
            'item_count' => 2,
            'items' => [
                ['index' => 0, 'key' => 'k0', 'status' => 'ok', 'detail' => ['previous' => 'a', 'value' => 'b']],
            ],
        ], $result->getAuditSummary());
    }

    /**
     * Through the real executor: a tool without the interface keeps today's audit summary exactly.
     */
    public function testPlainToolKeepsTodaysAuditSummaryWithTheRealExecutor(): void
    {
        $tool = $this->tool();
        $tool->method('getItemKeyField')->willReturn('sku');
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $adapter = new BulkToolAdapter(
            $tool,
            $this->schemaFactory,
            new BulkExecutor($this->createMock(AclChecker::class), $this->createMock(LoggerInterface::class)),
            $this->scheduler,
            $this->provider
        );

        $result = $adapter->execute(['items' => [['sku' => 'a'], ['sku' => 'b']]]);

        self::assertSame(
            ['total' => 2, 'ok' => 2, 'failed' => 0, 'skipped' => 0, 'item_count' => 2],
            $result->getAuditSummary()
        );
    }
}
