<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data\OAuth;

/**
 * One-shot OAuth 2.1 authorization code carrying a PKCE challenge.
 *
 * Issued at /oauth/authorize after admin consent, redeemed once at /oauth/token for an access
 * token. TTL is 60 seconds; the {@see self::USED_AT} column flips the moment a code is exchanged
 * so replay attempts fail even within the TTL window.
 */
interface AuthCodeInterface
{
    public const ID = 'id';
    public const CODE_HASH = 'code_hash';
    public const OAUTH_CLIENT_ID = 'oauth_client_id';
    public const ADMIN_USER_ID = 'admin_user_id';
    public const REDIRECT_URI = 'redirect_uri';
    public const CODE_CHALLENGE = 'code_challenge';
    public const CODE_CHALLENGE_METHOD = 'code_challenge_method';
    public const SCOPE = 'scope';
    public const GRANTED_TOOLS_JSON = 'granted_tools_json';
    public const EXPIRES_AT = 'expires_at';
    public const USED_AT = 'used_at';
    public const CREATED_AT = 'created_at';

    /**
     * @return int|null
     */
    public function getId(): ?int;

    /**
     * HMAC-SHA256 hash of the plaintext code; plaintext is never stored.
     *
     * @return string
     */
    public function getCodeHash(): string;

    /**
     * @param string $hash
     * @return self
     */
    public function setCodeHash(string $hash): self;

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
     * Admin user who approved the consent — the eventual access token's principal.
     *
     * @return int
     */
    public function getAdminUserId(): int;

    /**
     * @param int $id
     * @return self
     */
    public function setAdminUserId(int $id): self;

    /**
     * @return string
     */
    public function getRedirectUri(): string;

    /**
     * @param string $uri
     * @return self
     */
    public function setRedirectUri(string $uri): self;

    /**
     * Base64url-encoded SHA256 challenge from PKCE.
     *
     * @return string
     */
    public function getCodeChallenge(): string;

    /**
     * @param string $challenge
     * @return self
     */
    public function setCodeChallenge(string $challenge): self;

    /**
     * @return string
     */
    public function getCodeChallengeMethod(): string;

    /**
     * @param string $method
     * @return self
     */
    public function setCodeChallengeMethod(string $method): self;

    /**
     * @return string|null
     */
    public function getScope(): ?string;

    /**
     * @param string|null $scope
     * @return self
     */
    public function setScope(?string $scope): self;

    /**
     * MCP tool names granted at consent. The token endpoint replays this list
     * onto the access token's scopes_json column at exchange time.
     *
     * @return array<int, string>|null
     */
    public function getGrantedTools(): ?array;

    /**
     * @param array<int, string>|null $tools
     * @return self
     */
    public function setGrantedTools(?array $tools): self;

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
     * GMT datetime in `Y-m-d H:i:s` format, or null if the code is unused.
     *
     * @return string|null
     */
    public function getUsedAt(): ?string;

    /**
     * @param string|null $usedAt
     * @return self
     */
    public function setUsedAt(?string $usedAt): self;

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
    public function isUsed(): bool;

    /**
     * @return bool
     */
    public function isValid(): bool;
}
