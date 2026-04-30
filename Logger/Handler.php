<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

/**
 * Routes MCP log events into `var/log/magebit_mcp.log`.
 */
class Handler extends Base
{
    /** @var string */
    protected $fileName = '/var/log/magebit_mcp.log';

    /** @var int */
    protected $loggerType = MonologLogger::DEBUG;
}
