<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\Data\OAuth\RefreshTokenInterface;
use Magebit\Mcp\Model\OAuth\ResourceModel\RefreshToken as RefreshTokenResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record OAuth refresh token. Timestamps are stored and compared in UTC.
 */
class RefreshToken extends AbstractModel implements RefreshTokenInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(RefreshTokenResource::class);
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
    public function getTokenHash(): string
    {
        $v = $this->getData(self::TOKEN_HASH);
        return is_scalar($v) ? (string) $v : '';
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
    public function getOAuthClientId(): int
    {
        $v = $this->getData(self::OAUTH_CLIENT_ID);
        return is_scalar($v) ? (int) $v : 0;
    }

    /**
     * @inheritDoc
     */
    public function setOAuthClientId(int $id): self
    {
        $this->setData(self::OAUTH_CLIENT_ID, $id);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAccessTokenId(): int
    {
        $v = $this->getData(self::ACCESS_TOKEN_ID);
        return is_scalar($v) ? (int) $v : 0;
    }

    /**
     * @inheritDoc
     */
    public function setAccessTokenId(int $id): self
    {
        $this->setData(self::ACCESS_TOKEN_ID, $id);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getExpiresAt(): string
    {
        $v = $this->getData(self::EXPIRES_AT);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setExpiresAt(string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
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
    public function setRevokedAt(?string $revokedAt): self
    {
        $this->setData(self::REVOKED_AT, $revokedAt);
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
    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        if ($expiresAt === '') {
            return true;
        }
        // Column stores GMT; force the parser to treat the string as UTC so the comparison
        // against time() (which is TZ-independent) is correct regardless of the server TZ.
        $ts = strtotime($expiresAt . ' UTC');
        if ($ts === false) {
            return true;
        }
        return $ts <= time();
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
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }
}
