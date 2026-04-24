<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Exception;

use RuntimeException;

/**
 * Raised by {@see \Magebit\Mcp\Api\RateLimiterInterface} implementations when
 * the caller has exhausted their allowance for a tool. {@see
 * \Magebit\Mcp\Model\JsonRpc\Handler\ToolsCallHandler} catches this exception
 * specifically and translates it into the `-32013 RATE_LIMITED` JSON-RPC
 * envelope, embedding the carried {@see self::$limit} and {@see
 * self::$retryAfterSeconds} in the error `data` so clients can back off
 * intelligently.
 *
 * Deliberately not a {@see \Magento\Framework\Exception\LocalizedException}:
 * the tool-execution catch inside the handler already maps that type to
 * `-32011 TOOL_EXECUTION_FAILED`, and we want the two signals to stay
 * distinct.
 */
class RateLimitedException extends RuntimeException
{
    /**
     * @param string $message Human-readable message safe to surface to clients.
     * @param int $limit Requests-per-minute budget the caller just hit.
     * @param int $retryAfterSeconds Seconds until the current window rolls over (1..60).
     */
    public function __construct(
        string $message,
        private readonly int $limit,
        private readonly int $retryAfterSeconds
    ) {
        parent::__construct($message);
    }

    /**
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @return int
     */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
