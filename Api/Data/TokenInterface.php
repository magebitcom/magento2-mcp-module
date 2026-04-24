<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data;

/**
 * MCP bearer token bound to one admin user; the admin's current role is re-checked at auth time.
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
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * @return int
     */
    public function getAdminUserId(): int;

    /**
     * @param int $adminUserId
     * @return self
     */
    public function setAdminUserId(int $adminUserId): self;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self;

    /**
     * HMAC hash of the plaintext bearer; plaintext is never stored.
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
     * Null / empty means "every tool the admin's role grants".
     *
     * @return array<int, string>|null
     */
    public function getScopes(): ?array;

    /**
     * @param array|null $scopes
     * @phpstan-param array<int, string>|null $scopes
     * @return self
     */
    public function setScopes(?array $scopes): self;

    /**
     * WRITE-mode eligibility; global config must also allow.
     *
     * @return bool
     */
    public function getAllowWrites(): bool;

    /**
     * @param bool $allow
     * @return self
     */
    public function setAllowWrites(bool $allow): self;

    /**
     * @return string|null
     */
    public function getLastUsedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setLastUsedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getExpiresAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setExpiresAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getRevokedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setRevokedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return bool
     */
    public function isRevoked(): bool;

    /**
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
