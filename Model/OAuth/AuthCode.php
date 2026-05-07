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
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record OAuth authorization code. Timestamps are stored and compared in UTC.
 */
class AuthCode extends AbstractModel implements AuthCodeInterface
{
    use ReturnsStrictTypedData;

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AuthCodeResource::class);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->getDataIntOrNull(self::ID, cast: true);
    }

    /**
     * @return string
     */
    public function getCodeHash(): string
    {
        return $this->getDataString(self::CODE_HASH);
    }

    /**
     * @param string $hash
     * @return self
     */
    public function setCodeHash(string $hash): self
    {
        $this->setData(self::CODE_HASH, $hash);
        return $this;
    }

    /**
     * @return int
     */
    public function getOAuthClientId(): int
    {
        return $this->getDataInt(self::OAUTH_CLIENT_ID, cast: true);
    }

    /**
     * @param int $id
     * @return self
     */
    public function setOAuthClientId(int $id): self
    {
        $this->setData(self::OAUTH_CLIENT_ID, $id);
        return $this;
    }

    /**
     * @return int
     */
    public function getAdminUserId(): int
    {
        return $this->getDataInt(self::ADMIN_USER_ID, cast: true);
    }

    /**
     * @param int $id
     * @return self
     */
    public function setAdminUserId(int $id): self
    {
        $this->setData(self::ADMIN_USER_ID, $id);
        return $this;
    }

    /**
     * @return string
     */
    public function getRedirectUri(): string
    {
        return $this->getDataString(self::REDIRECT_URI);
    }

    /**
     * @param string $uri
     * @return self
     */
    public function setRedirectUri(string $uri): self
    {
        $this->setData(self::REDIRECT_URI, $uri);
        return $this;
    }

    /**
     * @return string
     */
    public function getCodeChallenge(): string
    {
        return $this->getDataString(self::CODE_CHALLENGE);
    }

    /**
     * @param string $challenge
     * @return self
     */
    public function setCodeChallenge(string $challenge): self
    {
        $this->setData(self::CODE_CHALLENGE, $challenge);
        return $this;
    }

    /**
     * @return string
     */
    public function getCodeChallengeMethod(): string
    {
        return $this->getDataString(self::CODE_CHALLENGE_METHOD);
    }

    /**
     * @param string $method
     * @return self
     */
    public function setCodeChallengeMethod(string $method): self
    {
        $this->setData(self::CODE_CHALLENGE_METHOD, $method);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getScope(): ?string
    {
        return $this->getDataStringOrNull(self::SCOPE);
    }

    /**
     * @param string|null $scope
     * @return self
     */
    public function setScope(?string $scope): self
    {
        $this->setData(self::SCOPE, $scope);
        return $this;
    }

    /**
     * @return array<int, string>|null
     */
    public function getGrantedTools(): ?array
    {
        $raw = $this->getDataStringOrNull(self::GRANTED_TOOLS_JSON);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $tools = array_values(array_unique(array_filter($decoded, 'is_string')));
        return $tools === [] ? null : $tools;
    }

    /**
     * @param array<int, string>|null $tools
     * @return self
     */
    public function setGrantedTools(?array $tools): self
    {
        $tools = $tools === null ? [] : array_values(array_unique(array_filter($tools, 'is_string')));
        if ($tools === []) {
            $this->setData(self::GRANTED_TOOLS_JSON, null);
            return $this;
        }
        $encoded = json_encode($tools, JSON_UNESCAPED_SLASHES);
        $this->setData(self::GRANTED_TOOLS_JSON, $encoded === false ? null : $encoded);
        return $this;
    }

    /**
     * @return string
     */
    public function getExpiresAt(): string
    {
        return $this->getDataString(self::EXPIRES_AT);
    }

    /**
     * @param string $expiresAt
     * @return self
     */
    public function setExpiresAt(string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getUsedAt(): ?string
    {
        return $this->getDataStringOrNull(self::USED_AT);
    }

    /**
     * @param string|null $usedAt
     * @return self
     */
    public function setUsedAt(?string $usedAt): self
    {
        $this->setData(self::USED_AT, $usedAt);
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
     * @return bool
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        if ($expiresAt === '') {
            return true;
        }
        // Column stores UTC; force UTC parsing so comparison against time() is TZ-independent.
        $ts = strtotime($expiresAt . ' UTC');
        return $ts === false || $ts <= time();
    }

    /**
     * @return bool
     */
    public function isUsed(): bool
    {
        return $this->getUsedAt() !== null;
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}
