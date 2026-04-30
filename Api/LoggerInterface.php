<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Marker logger routing module log calls into `var/log/magebit_mcp.log` via DI preference.
 */
interface LoggerInterface extends PsrLoggerInterface
{
}
