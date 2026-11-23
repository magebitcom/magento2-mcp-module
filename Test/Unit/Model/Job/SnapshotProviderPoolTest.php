<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use InvalidArgumentException;
use Magebit\Mcp\Api\JobSnapshotProviderInterface;
use Magebit\Mcp\Model\Job\SnapshotProviderPool;
use PHPUnit\Framework\TestCase;
use stdClass;

class SnapshotProviderPoolTest extends TestCase
{
    /**
     * @return void
     */
    public function testReturnsProviderByToolName(): void
    {
        $provider = $this->createMock(JobSnapshotProviderInterface::class);
        $pool = new SnapshotProviderPool(['catalog.product.update' => $provider]);

        self::assertSame($provider, $pool->get('catalog.product.update'));
        self::assertNull($pool->get('catalog.product.delete'));
    }

    /**
     * @return void
     */
    public function testRejectsEntryThatIsNotAProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');
        new SnapshotProviderPool(['catalog.product.update' => new stdClass()]);
    }

    /**
     * @return void
     */
    public function testRejectsKeyThatIsNotACanonicalToolName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical tool name');
        new SnapshotProviderPool([
            'catalog_product_update' => $this->createMock(JobSnapshotProviderInterface::class),
        ]);
    }
}
