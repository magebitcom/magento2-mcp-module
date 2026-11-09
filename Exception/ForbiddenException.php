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
 * Thrown from a tool's `execute()` when the caller may not act on the requested entity; answers `-32004 FORBIDDEN`.
 * Not a LocalizedException, which answers `-32011`. The message reaches the client verbatim.
 */
class ForbiddenException extends RuntimeException
{
    /**
     * @param string $message
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        string $message,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    /**
     * Structured detail for the JSON-RPC `error.data` member, such as a `reason` or the refused ids.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
