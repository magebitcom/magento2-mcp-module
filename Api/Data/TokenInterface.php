<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data;

/**
 * MCP bearer token.
 *
 * Each token is bound to exactly one admin user. At authentication time the
 * admin's role is re-checked — a token can only do what the admin's role still
 * permits today, never what it permitted at mint time.
 */
interface TokenInterface
{
    public const ID = 'id';
    public const ADMIN_USER_ID = 'admin_user_id';
    public const NAME = 'name';
    public const TOKEN_HASH = 'token_hash';
    public const SCOPES_JSON = 'scopes_json';
    public const ALLOW_WRITES = 'allow_writes';
    public const LAST_USED_AT = 'last_used_at';
    public const EXPIRES_AT = 'expires_at';
    public const REVOKED_AT = 'revoked_at';
    public const CREATED_AT = 'created_at';

    /**
     * Primary key. Null for unpersisted rows.
     *
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Admin user this token is bound to.
     *
     * @return int
     */
    public function getAdminUserId(): int;

    /**
     * Set the admin user id.
     *
     * @param int $adminUserId
     * @return self
     */
    public function setAdminUserId(int $adminUserId): self;

    /**
     * Human-readable label shown in the admin grid.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set the human-readable label.
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): self;

    /**
     * HMAC hash of the plaintext bearer — the plaintext is never stored.
     *
     * @return string
     */
    public function getTokenHash(): string;

    /**
     * Set the HMAC hash for the bearer.
     *
     * @param string $hash
     * @return self
     */
    public function setTokenHash(string $hash): self;

    /**
     * Tool-name allowlist (intersection with the admin's role ACL wins on callsite).
     *
     * Null / empty means "every tool the admin's role grants".
     *
     * @return array<int, string>|null
     */
    public function getScopes(): ?array;

    /**
     * Set the tool-name allowlist, or null to grant every tool in-ACL.
     *
     * @param array|null $scopes
     * @phpstan-param array<int, string>|null $scopes
     * @return self
     */
    public function setScopes(?array $scopes): self;

    /**
     * True if this token may call WRITE-mode tools (global config must also allow).
     *
     * @return bool
     */
    public function getAllowWrites(): bool;

    /**
     * Toggle the allow-writes flag.
     *
     * @param bool $allow
     * @return self
     */
    public function setAllowWrites(bool $allow): self;

    /**
     * Timestamp of the most recent successful authentication, or null if never used.
     *
     * @return string|null
     */
    public function getLastUsedAt(): ?string;

    /**
     * Set the last-used timestamp.
     *
     * @param string|null $timestamp
     * @return self
     */
    public function setLastUsedAt(?string $timestamp): self;

    /**
     * Expiration timestamp, or null for non-expiring tokens.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string;

    /**
     * Set the expiration timestamp, or null for non-expiring.
     *
     * @param string|null $timestamp
     * @return self
     */
    public function setExpiresAt(?string $timestamp): self;

    /**
     * Revocation timestamp, or null if the token is still live.
     *
     * @return string|null
     */
    public function getRevokedAt(): ?string;

    /**
     * Set the revocation timestamp.
     *
     * @param string|null $timestamp
     * @return self
     */
    public function setRevokedAt(?string $timestamp): self;

    /**
     * Creation timestamp written at insert time.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * True if the token has been explicitly revoked.
     *
     * @return bool
     */
    public function isRevoked(): bool;

    /**
     * True if the expiration timestamp is set and already in the past.
     *
     * @return bool
     */
    public function isExpired(): bool;

    /**
     * True iff the token is neither revoked nor expired.
     *
     * @return bool
     */
    public function isActive(): bool;
}
