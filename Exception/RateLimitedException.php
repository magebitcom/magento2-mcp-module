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
 * Rate-limit breach; mapped to `-32013 RATE_LIMITED`. Deliberately NOT a
 * {@see \Magento\Framework\Exception\LocalizedException} — that type maps to
 * `-32011 TOOL_EXECUTION_FAILED` and the two signals must stay distinct.
 */
class RateLimitedException extends RuntimeException
{
    /**
     * @param int $limit
     * @param int $retryAfterSeconds
     */
    public function __construct(
        string $message,
        private readonly int $limit,
        private readonly int $retryAfterSeconds
    ) {
        parent::__construct($message);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
