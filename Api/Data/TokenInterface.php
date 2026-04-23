<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
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

    public function getId(): ?int;

    public function getAdminUserId(): int;

    public function setAdminUserId(int $adminUserId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getTokenHash(): string;

    public function setTokenHash(string $hash): self;

    /**
     * Tool-name allowlist (intersection with the admin's role ACL wins on callsite).
     * Null / empty means "every tool the admin's role grants".
     *
     * @return array<int, string>|null
     */
    public function getScopes(): ?array;

    /**
     * @param array<int, string>|null $scopes
     */
    public function setScopes(?array $scopes): self;

    public function getAllowWrites(): bool;

    public function setAllowWrites(bool $allow): self;

    public function getLastUsedAt(): ?string;

    public function setLastUsedAt(?string $timestamp): self;

    public function getExpiresAt(): ?string;

    public function setExpiresAt(?string $timestamp): self;

    public function getRevokedAt(): ?string;

    public function setRevokedAt(?string $timestamp): self;

    public function getCreatedAt(): ?string;

    public function isRevoked(): bool;

    public function isExpired(): bool;

    public function isActive(): bool;
}
