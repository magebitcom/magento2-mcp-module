<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Invoked by the JSON-RPC dispatcher immediately before every tools/call.
 *
 * The module ships a no-op default ({@see \Magebit\Mcp\Model\RateLimiter\NoOpRateLimiter}).
 * Real implementations override the preference in etc/di.xml.
 */
interface RateLimiterInterface
{
    /**
     * @throws LocalizedException When the caller has exceeded their allowance for this tool.
     */
    public function check(int $adminUserId, string $toolName): void;
}
