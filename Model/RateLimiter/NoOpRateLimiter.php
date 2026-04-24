<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\RateLimiter;

use Magebit\Mcp\Api\RateLimiterInterface;

/**
 * Default rate limiter — never throttles. Override via DI preference in etc/di.xml.
 */
class NoOpRateLimiter implements RateLimiterInterface
{
    /**
     * @inheritDoc
     */
    public function check(int $adminUserId, string $toolName): void
    {
        unset($adminUserId, $toolName);
    }
}
