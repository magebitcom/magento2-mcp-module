<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Immutable result of an OAuth 2.1 access-token mint. Plaintext access + refresh
 * tokens are exposed to the caller exactly once; the DB stores only their hashes.
 */
class IssuedTokenPair
{
    /**
     * @param string $accessToken
     * @param int $accessTokenId
     * @param int $expiresIn
     * @param string $refreshToken
     * @param int $refreshTokenId
     * @param string|null $grantedScope
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $accessTokenId,
        public readonly int $expiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshTokenId,
        public readonly ?string $grantedScope = null
    ) {
    }
}
