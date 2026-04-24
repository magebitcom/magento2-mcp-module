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
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record MCP token.
 *
 * Round-trips {@see TokenInterface::SCOPES_JSON} as a plain PHP array in
 * {@see self::getScopes()} / {@see self::setScopes()} so callers never have
 * to think about JSON serialization.
 */
class Token extends AbstractModel implements TokenInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(TokenResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getId(): ?int
    {
        $id = $this->getData(self::ID);
        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * @inheritDoc
     */
    public function getAdminUserId(): int
    {
        $id = $this->getData(self::ADMIN_USER_ID);
        return is_scalar($id) ? (int) $id : 0;
    }

    /**
     * @inheritDoc
     */
    public function setAdminUserId(int $adminUserId): self
    {
        $this->setData(self::ADMIN_USER_ID, $adminUserId);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $name = $this->getData(self::NAME);
        return is_scalar($name) ? (string) $name : '';
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTokenHash(): string
    {
        $hash = $this->getData(self::TOKEN_HASH);
        return is_scalar($hash) ? (string) $hash : '';
    }

    /**
     * @inheritDoc
     */
    public function setTokenHash(string $hash): self
    {
        $this->setData(self::TOKEN_HASH, $hash);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getScopes(): ?array
    {
        $raw = $this->getData(self::SCOPES_JSON);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $scopes = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $scopes[] = $item;
            }
        }
        return $scopes === [] ? null : $scopes;
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getAllowWrites(): bool
    {
        return (bool) $this->getData(self::ALLOW_WRITES);
    }

    /**
     * @inheritDoc
     */
    public function setAllowWrites(bool $allow): self
    {
        $this->setData(self::ALLOW_WRITES, $allow ? 1 : 0);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getLastUsedAt(): ?string
    {
        $v = $this->getData(self::LAST_USED_AT);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setLastUsedAt(?string $timestamp): self
    {
        $this->setData(self::LAST_USED_AT, $timestamp);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getExpiresAt(): ?string
    {
        $v = $this->getData(self::EXPIRES_AT);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setExpiresAt(?string $timestamp): self
    {
        $this->setData(self::EXPIRES_AT, $timestamp);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getRevokedAt(): ?string
    {
        $v = $this->getData(self::REVOKED_AT);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setRevokedAt(?string $timestamp): self
    {
        $this->setData(self::REVOKED_AT, $timestamp);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        $v = $this->getData(self::CREATED_AT);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function isRevoked(): bool
    {
        return $this->getRevokedAt() !== null;
    }

    /**
     * @inheritDoc
     */
    public function isExpired(): bool
    {
        $exp = $this->getExpiresAt();
        if ($exp === null) {
            return false;
        }
        // expires_at is always stored as UTC (see TokenCreateCommand); parse it
        // explicitly in UTC so servers on non-UTC timezones don't drift hours.
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $exp, new \DateTimeZone('UTC'));
        return $dt !== false && $dt->getTimestamp() < time();
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
