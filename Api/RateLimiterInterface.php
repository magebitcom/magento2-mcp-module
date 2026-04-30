<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Invoked before every tools/call; ships as a no-op default and is overridden via DI preference.
 */
interface RateLimiterInterface
{
    /**
     * @param int $adminUserId
     * @param string $toolName
     * @return void
     * @throws LocalizedException When the caller has exceeded their allowance for this tool.
     */
    public function check(int $adminUserId, string $toolName): void;
}
