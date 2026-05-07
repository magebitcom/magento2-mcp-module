<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\ResourceModel\Token as TokenResource;
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record MCP bearer token. Scopes round-trip as a JSON-encoded tool list.
 */
class Token extends AbstractModel implements TokenInterface
{
    use ReturnsStrictTypedData;

    protected function _construct(): void
    {
        $this->_init(TokenResource::class);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->getDataIntOrNull(self::ID, cast: true);
    }

    /**
     * @return int
     */
    public function getAdminUserId(): int
    {
        return $this->getDataInt(self::ADMIN_USER_ID, cast: true);
    }

    /**
     * @param int $adminUserId
     * @return self
     */
    public function setAdminUserId(int $adminUserId): self
    {
        $this->setData(self::ADMIN_USER_ID, $adminUserId);
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->getDataString(self::NAME);
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
        return $this;
    }

    /**
     * @return string
     */
    public function getTokenHash(): string
    {
        return $this->getDataString(self::TOKEN_HASH);
    }

    /**
     * @param string $hash
     * @return self
     */
    public function setTokenHash(string $hash): self
    {
        $this->setData(self::TOKEN_HASH, $hash);
        return $this;
    }

    /**
     * @return array<int, string>|null
     */
    public function getScopes(): ?array
    {
        $raw = $this->getDataStringOrNull(self::SCOPES_JSON);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $scopes = array_values(array_filter(
            $decoded,
            static fn (mixed $v): bool => is_string($v) && $v !== ''
        ));
        return $scopes === [] ? null : $scopes;
    }

    /**
     * @param array<int, string>|null $scopes
     * @return self
     */
    public function setScopes(?array $scopes): self
    {
        if ($scopes === null || $scopes === []) {
            $this->setData(self::SCOPES_JSON, null);
            return $this;
        }
        $encoded = json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES);
        $this->setData(self::SCOPES_JSON, $encoded === false ? null : $encoded);
        return $this;
    }

    /**
     * @return bool
     */
    public function getAllowWrites(): bool
    {
        return (bool) $this->getData(self::ALLOW_WRITES);
    }

    /**
     * @param bool $allow
     * @return self
     */
    public function setAllowWrites(bool $allow): self
    {
        $this->setData(self::ALLOW_WRITES, $allow ? 1 : 0);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLastUsedAt(): ?string
    {
        return $this->getDataStringOrNull(self::LAST_USED_AT);
    }

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setLastUsedAt(?string $timestamp): self
    {
        $this->setData(self::LAST_USED_AT, $timestamp);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->getDataStringOrNull(self::EXPIRES_AT);
    }

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setExpiresAt(?string $timestamp): self
    {
        $this->setData(self::EXPIRES_AT, $timestamp);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRevokedAt(): ?string
    {
        return $this->getDataStringOrNull(self::REVOKED_AT);
    }

    /**
     * @param string|null $timestamp
     * @return self
     */
    public function setRevokedAt(?string $timestamp): self
    {
        $this->setData(self::REVOKED_AT, $timestamp);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getDataStringOrNull(self::CREATED_AT);
    }

    /**
     * @return int|null
     */
    public function getOAuthClientId(): ?int
    {
        $id = $this->getDataIntOrNull(self::OAUTH_CLIENT_ID, cast: true);
        return $id !== null && $id > 0 ? $id : null;
    }

    /**
     * @param int|null $id
     * @return self
     */
    public function setOAuthClientId(?int $id): self
    {
        $this->setData(self::OAUTH_CLIENT_ID, $id);
        return $this;
    }

    /**
     * @return bool
     */
    public function isRevoked(): bool
    {
        return $this->getRevokedAt() !== null;
    }

    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        $exp = $this->getExpiresAt();
        if ($exp === null) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $exp, new \DateTimeZone('UTC'));
        return $dt !== false && $dt->getTimestamp() < time();
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
