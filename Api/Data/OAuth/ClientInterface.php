<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data\OAuth;

/**
 * OAuth 2.1 pre-registered client. Issues MCP access tokens via the
 * authorization_code (PKCE) and refresh_token grants.
 */
interface ClientInterface
{
    public const ID = 'id';
    public const CLIENT_ID = 'client_id';
    public const CLIENT_SECRET_HASH = 'client_secret_hash';
    public const NAME = 'name';
    public const REDIRECT_URIS_JSON = 'redirect_uris_json';
    public const ALLOWED_TOOLS_JSON = 'allowed_tools_json';
    public const CREATED_AT = 'created_at';

    /**
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * Public client identifier (UUID v4).
     *
     * @return string
     */
    public function getClientId(): string;

    /**
     * @param string $clientId
     * @return self
     */
    public function setClientId(string $clientId): self;

    /**
     * HMAC hash of the plaintext secret; plaintext is never stored.
     *
     * @return string
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
     * Allowed redirect URIs for the authorization-code flow (exact match).
     *
     * @return array<int, string>
     */
    public function getRedirectUris(): array;

    /**
     * @param array $uris
     * @phpstan-param array<int, string> $uris
     * @return self
     */
    public function setRedirectUris(array $uris): self;

    /**
     * MCP tool names this client may request at consent time. The consent
     * screen lets the approving admin tighten this further; the runtime
     * dispatcher enforces both the per-token grant and the admin's role.
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
     * @return string|null
     */
    public function getCreatedAt(): ?string;
}
