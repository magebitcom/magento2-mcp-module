<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

/**
 * Generates cryptographically random plaintext bearer tokens.
 *
 * 32 bytes = 256 bits of entropy → 64 hex characters. Matches the strength
 * we'd expect from a production API key and is well within MCP client config
 * size limits.
 */
class TokenGenerator
{
    private const BYTE_LENGTH = 32;

    /**
     * Produce a 64-character hex token backed by `random_bytes()`.
     *
     * @return string
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(self::BYTE_LENGTH));
    }
}
