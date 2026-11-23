<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool;

use Magebit\Mcp\Test\Unit\Contract\WireNameTextTestCase;

/**
 * Core's own run of the shared wire-name check.
 */
class WireNameTextTest extends WireNameTextTestCase
{
    protected function moduleDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
