<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\RateLimiter;

use Magebit\Mcp\Api\RateLimiterInterface;

/**
 * Default rate limiter — never throttles.
 *
 * Real implementations override the DI preference in etc/di.xml. The interface
 * is called on every tools/call in the dispatcher so adding a real limiter later
 * is a zero-code-change swap from the caller's perspective.
 */
class NoOpRateLimiter implements RateLimiterInterface
{
    /**
     * @inheritDoc
     */
    public function check(int $adminUserId, string $toolName): void
    {
        // No-op by design — see class doc. Suppress unused-param lint.
        unset($adminUserId, $toolName);
    }
}
