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
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class JobItemKeysTest extends TestCase
{
    public function testFromItemsUsesTheKeyFieldFromTheGivenIndex(): void
    {
        $keys = (new JobItemKeys($this->createMock(ToolRegistryInterface::class)))
            ->fromItems([['sku' => 'a'], ['sku' => 7], ['x' => 1], 'not-an-object', ['sku' => ['nested']]], 1, 'sku');

        self::assertSame([1 => '7', 2 => null, 3 => null, 4 => null], $keys);
    }

    public function testForJobResolvesTheKeyFieldOfTheJobsBulkTool(): void
    {
        $bulk = $this->createMock(BulkToolInterface::class);
        $bulk->method('getItemKeyField')->willReturn('sku');
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('getBulkTool')->willReturn($bulk);
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->with('catalog.product.bulk_update')->willReturn($adapter);

        $keys = (new JobItemKeys($registry))->forJob($this->job([['sku' => 'a'], ['sku' => 'b']]), 1);

        self::assertSame([1 => 'b'], $keys);
    }

    public function testForJobOfAnUnknownToolHasNoKeys(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->willThrowException(NoSuchEntityException::singleField('name', 'x'));

        self::assertSame([], (new JobItemKeys($registry))->forJob($this->job([['sku' => 'a']])));
    }

    public function testForJobOfANonBulkToolHasNoKeys(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->willReturn($this->createMock(ToolInterface::class));

        self::assertSame([], (new JobItemKeys($registry))->forJob($this->job([['sku' => 'a']])));
    }

    /**
     * @param array<int, mixed> $items
     * @return JobInterface
     */
    private function job(array $items): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getToolName')->willReturn('catalog.product.bulk_update');
        $job->method('getItems')->willReturn($items);
        return $job;
    }
}
