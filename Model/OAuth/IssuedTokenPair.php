<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Immutable result of an OAuth 2.1 access-token mint: the freshly issued
 * access-token plaintext + row id, the matching expires-in (seconds), and the
 * paired refresh-token plaintext + row id. Plaintext values are returned to
 * the caller exactly once — only the hashes are persisted.
 */
final class IssuedTokenPair
{
    /**
     * @param string $accessToken
     * @param int $accessTokenId
     * @param int $expiresIn
     * @param string $refreshToken
     * @param int $refreshTokenId
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $accessTokenId,
        public readonly int $expiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshTokenId
    ) {
    }
}
