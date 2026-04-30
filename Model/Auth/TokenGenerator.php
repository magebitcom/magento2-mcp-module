<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

/**
 * Generates cryptographically random plaintext bearer tokens.
 * 32 bytes = 256 bits of entropy → 64 hex characters.
 */
class TokenGenerator
{
    private const BYTE_LENGTH = 32;

    /**
     * @return string
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(self::BYTE_LENGTH));
    }
}
