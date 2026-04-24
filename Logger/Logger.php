<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Logger;

use Magebit\Mcp\Api\LoggerInterface;
use Monolog\Logger as MonologLogger;

/**
 * Dedicated Monolog channel for the MCP module; see {@see LoggerInterface}.
 */
class Logger extends MonologLogger implements LoggerInterface
{
}
