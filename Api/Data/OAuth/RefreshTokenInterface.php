<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data\OAuth;

/**
 * OAuth 2.1 refresh token paired with a previously-issued access token.
 *
 * Issued at /oauth/token alongside an access token; presented later (with
 * `grant_type=refresh_token`) to mint a new access token. Rotation strategy is
 * revoke-on-use: the rotator stamps {@see self::REVOKED_AT} on the presented
 * token in the same statement that issues its successor, so a leaked refresh
 * token is single-use even within the TTL window.
 */
interface RefreshTokenInterface
{
    public const ID = 'id';
    public const TOKEN_HASH = 'token_hash';
    public const OAUTH_CLIENT_ID = 'oauth_client_id';
    public const ACCESS_TOKEN_ID = 'access_token_id';
    public const EXPIRES_AT = 'expires_at';
    public const REVOKED_AT = 'revoked_at';
    public const CREATED_AT = 'created_at';

    /**
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * HMAC-SHA256 hash of the plaintext refresh token; plaintext is never stored.
     *
     * @return string
     */
    public function getTokenHash(): string;

    /**
     * @param string $hash
     * @return self
     */
    public function setTokenHash(string $hash): self;

    /**
     * @return int
     */
    public function getOAuthClientId(): int;

    /**
     * @param int $id
     * @return self
     */
    public function setOAuthClientId(int $id): self;

    /**
     * FK to the `magebit_mcp_token` row this refresh token was paired with.
     *
     * @return int
     */
    public function getAccessTokenId(): int;

    /**
     * @param int $id
     * @return self
     */
    public function setAccessTokenId(int $id): self;

    /**
     * GMT datetime in `Y-m-d H:i:s` format.
     *
     * @return string
     */
    public function getExpiresAt(): string;

    /**
     * @param string $expiresAt
     * @return self
     */
    public function setExpiresAt(string $expiresAt): self;

    /**
     * GMT datetime in `Y-m-d H:i:s` format, or null while the token is active.
     *
     * @return string|null
     */
    public function getRevokedAt(): ?string;

    /**
     * @param string|null $revokedAt
     * @return self
     */
    public function setRevokedAt(?string $revokedAt): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return bool
     */
    public function isExpired(): bool;

    /**
     * @return bool
     */
    public function isRevoked(): bool;

    /**
     * @return bool
     */
    public function isValid(): bool;
}
