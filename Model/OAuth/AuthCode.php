<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\Data\OAuth\AuthCodeInterface;
use Magebit\Mcp\Model\OAuth\ResourceModel\AuthCode as AuthCodeResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record OAuth authorization code. Timestamps are stored and compared in UTC.
 */
class AuthCode extends AbstractModel implements AuthCodeInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(AuthCodeResource::class);
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
    public function getCodeHash(): string
    {
        $v = $this->getData(self::CODE_HASH);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setCodeHash(string $hash): self
    {
        $this->setData(self::CODE_HASH, $hash);
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
    public function getAdminUserId(): int
    {
        $v = $this->getData(self::ADMIN_USER_ID);
        return is_scalar($v) ? (int) $v : 0;
    }

    /**
     * @inheritDoc
     */
    public function setAdminUserId(int $id): self
    {
        $this->setData(self::ADMIN_USER_ID, $id);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getRedirectUri(): string
    {
        $v = $this->getData(self::REDIRECT_URI);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setRedirectUri(string $uri): self
    {
        $this->setData(self::REDIRECT_URI, $uri);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCodeChallenge(): string
    {
        $v = $this->getData(self::CODE_CHALLENGE);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setCodeChallenge(string $challenge): self
    {
        $this->setData(self::CODE_CHALLENGE, $challenge);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCodeChallengeMethod(): string
    {
        $v = $this->getData(self::CODE_CHALLENGE_METHOD);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setCodeChallengeMethod(string $method): self
    {
        $this->setData(self::CODE_CHALLENGE_METHOD, $method);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getScope(): ?string
    {
        $v = $this->getData(self::SCOPE);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setScope(?string $scope): self
    {
        $this->setData(self::SCOPE, $scope);
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
    public function getUsedAt(): ?string
    {
        $v = $this->getData(self::USED_AT);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @inheritDoc
     */
    public function setUsedAt(?string $usedAt): self
    {
        $this->setData(self::USED_AT, $usedAt);
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
    public function isUsed(): bool
    {
        return $this->getUsedAt() !== null;
    }

    /**
     * @inheritDoc
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}
