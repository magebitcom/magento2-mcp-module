<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data\OAuth;

/**
 * OAuth 2.1 pre-registered client.
 */
interface ClientInterface
{
    public const ID = 'id';
    public const CLIENT_ID = 'client_id';
    public const CLIENT_SECRET_HASH = 'client_secret_hash';
    public const NAME = 'name';
    public const REDIRECT_URIS_JSON = 'redirect_uris_json';
    public const ALLOWED_TOOLS_JSON = 'allowed_tools_json';
    public const AUTH_MODE = 'auth_mode';
    public const SERVICE_ADMIN_USER_ID = 'service_admin_user_id';
    public const ALLOWED_ADMIN_USER_IDS_JSON = 'allowed_admin_user_ids_json';
    public const ALLOWED_ADMIN_ROLE_IDS_JSON = 'allowed_admin_role_ids_json';
    public const DISABLED = 'disabled';
    public const CREATED_AT = 'created_at';

    /**
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * @return string Public client identifier (UUID v4).
     */
    public function getClientId(): string;

    /**
     * @param string $clientId
     * @return self
     */
    public function setClientId(string $clientId): self;

    /**
     * @return string HMAC hash; plaintext is never stored.
     */
    public function getClientSecretHash(): string;

    /**
     * @param string $hash
     * @return self
     */
    public function setClientSecretHash(string $hash): self;

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
     * @return array<int, string> Allowed redirect URIs (exact match).
     */
    public function getRedirectUris(): array;

    /**
     * @param array $uris
     * @phpstan-param array<int, string> $uris
     * @return self
     */
    public function setRedirectUris(array $uris): self;

    /**
     * Tool names this client may request at consent time; the consent screen and
     * runtime ACL narrow further.
     *
     * @return array<int, string>
     */
    public function getAllowedTools(): array;

    /**
     * @param array $tools
     * @phpstan-param array<int, string> $tools
     * @return self
     */
    public function setAllowedTools(array $tools): self;

    /**
     * @return \Magebit\Mcp\Model\OAuth\AuthMode
     */
    public function getAuthMode(): \Magebit\Mcp\Model\OAuth\AuthMode;

    /**
     * @param \Magebit\Mcp\Model\OAuth\AuthMode $mode
     * @return self
     */
    public function setAuthMode(\Magebit\Mcp\Model\OAuth\AuthMode $mode): self;

    /**
     * @return int|null Pinned admin in SHARED mode; NULL in PERSONAL mode.
     */
    public function getServiceAdminUserId(): ?int;

    /**
     * @param int|null $adminUserId
     * @return self
     */
    public function setServiceAdminUserId(?int $adminUserId): self;

    /**
     * Personal-mode admin-user whitelist; empty = no restriction. Union with
     * {@see getAllowedAdminRoleIds()}.
     *
     * @return array<int, int>
     */
    public function getAllowedAdminUserIds(): array;

    /**
     * @param array $userIds
     * @phpstan-param array<int, int> $userIds
     * @return self
     */
    public function setAllowedAdminUserIds(array $userIds): self;

    /**
     * Personal-mode admin-role whitelist; empty = no restriction. Union with
     * {@see getAllowedAdminUserIds()}.
     *
     * @return array<int, int>
     */
    public function getAllowedAdminRoleIds(): array;

    /**
     * @param array $roleIds
     * @phpstan-param array<int, int> $roleIds
     * @return self
     */
    public function setAllowedAdminRoleIds(array $roleIds): self;

    /**
     * @return bool When true the client is preserved but can't mint or refresh tokens.
     */
    public function isDisabled(): bool;

    /**
     * @param bool $disabled
     * @return self
     */
    public function setDisabled(bool $disabled): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;
}
