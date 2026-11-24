<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc;

use Magebit\Mcp\Model\JsonRpc\StructuredOutputPolicy;
use PHPUnit\Framework\TestCase;

class StructuredOutputPolicyTest extends TestCase
{
    public function testOldOrMissingHeaderUnsupported(): void
    {
        $policy = new StructuredOutputPolicy();

        self::assertFalse($policy->isSupported(null));
        self::assertFalse($policy->isSupported(''));
        self::assertFalse($policy->isSupported('  '));
        self::assertFalse($policy->isSupported('2025-03-26'));
        self::assertFalse($policy->isSupported(' 2025-03-26 '));
        self::assertFalse($policy->isSupported('2025-03-26, 2025-03-26'));
        self::assertFalse($policy->isSupported(' , '));
    }

    public function testCurrentHeaderSupported(): void
    {
        $policy = new StructuredOutputPolicy();

        self::assertTrue($policy->isSupported('2025-06-18'));
        self::assertTrue($policy->isSupported(' 2025-06-18'));
        self::assertTrue($policy->isSupported('2025-06-18, 2025-06-18'));
    }
}
