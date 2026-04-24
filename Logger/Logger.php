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
 * Dedicated Monolog channel for the MCP module.
 *
 * Concrete implementation of {@see LoggerInterface}. Consumers inside the
 * module depend on the interface and receive this instance through the
 * DI preference wired in `etc/di.xml`, which keeps all MCP events in a
 * dedicated log file.
 */
class Logger extends MonologLogger implements LoggerInterface
{
}
