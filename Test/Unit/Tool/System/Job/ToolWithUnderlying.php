<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;

/**
 * Mock target: a tool that also declares an underlying ACL resource.
 */
interface ToolWithUnderlying extends ToolInterface, UnderlyingAclAwareInterface
{
}
