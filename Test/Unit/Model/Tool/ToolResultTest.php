<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool;

use Magebit\Mcp\Api\StructuredToolResultInterface;
use Magebit\Mcp\Model\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function testStructuredContentDefaultsToNull(): void
    {
        $result = ToolResult::text('hi');
        self::assertInstanceOf(StructuredToolResultInterface::class, $result);
        self::assertNull($result->getStructuredContent());
    }

    public function testStructuredContentKept(): void
    {
        $result = new ToolResult([['type' => 'text', 'text' => 'x']], false, ['n' => 1], ['a' => [1, 2]]);
        self::assertSame(['a' => [1, 2]], $result->getStructuredContent());
        self::assertSame(['n' => 1], $result->getAuditSummary());
        self::assertFalse($result->isError());
    }
}
