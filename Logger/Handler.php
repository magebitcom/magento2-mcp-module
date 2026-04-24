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
 * Routes MCP-specific log events into `var/log/magebit_mcp.log`.
 *
 * Kept separate from Magento's system.log so the MCP audit trail — bad
 * schemas surfaced by the sanitizer, tool-execution exceptions, auth
 * failures worth watching — stays readable in a single file an operator
 * can tail without grepping past unrelated storefront noise.
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/magebit_mcp.log';

    /**
     * @var int
     */
    protected $loggerType = MonologLogger::DEBUG;
}
