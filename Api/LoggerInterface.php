<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Marker logger for the Magebit MCP module.
 *
 * Classes inside the module type-hint this interface instead of
 * {@see PsrLoggerInterface} so the DI preference in `etc/di.xml`
 * routes every log call into the dedicated `var/log/magebit_mcp.log`
 * channel without each consumer needing its own `<argument name="logger">`
 * override. Classes outside the module that want Magento's system log are
 * unaffected — they continue to depend on the PSR interface directly.
 */
interface LoggerInterface extends PsrLoggerInterface
{
}
