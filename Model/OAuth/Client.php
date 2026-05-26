<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Model\OAuth\ResourceModel\Client as ClientResource;
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record OAuth client. Redirect URIs and allowed-tool names round-trip as JSON.
 */
class Client extends AbstractModel implements ClientInterface
{
    use ReturnsStrictTypedData;

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ClientResource::class);
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
    public function getClientId(): string
    {
        return $this->getDataString(self::CLIENT_ID);
    }

    /**
     * @param string $clientId
     * @return self
     */
    public function setClientId(string $clientId): self
    {
        $this->setData(self::CLIENT_ID, $clientId);
        return $this;
    }

    /**
     * @return string
     */
    public function getClientSecretHash(): string
    {
        return $this->getDataString(self::CLIENT_SECRET_HASH);
    }

    /**
     * @param string $hash
     * @return self
     */
    public function setClientSecretHash(string $hash): self
    {
        $this->setData(self::CLIENT_SECRET_HASH, $hash);
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
     * @return array<int, string>
     */
    public function getRedirectUris(): array
    {
        return self::decodeStringList($this->getDataStringOrNull(self::REDIRECT_URIS_JSON));
    }

    /**
     * @param array<int, string> $uris
     * @return self
     */
    public function setRedirectUris(array $uris): self
    {
        $encoded = json_encode(array_values($uris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->setData(self::REDIRECT_URIS_JSON, $encoded);
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedTools(): array
    {
        $raw = $this->getDataStringOrNull(self::ALLOWED_TOOLS_JSON);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::dedupeStrings($decoded);
    }

    /**
     * @param array<int, string> $tools
     * @return self
     */
    public function setAllowedTools(array $tools): self
    {
        $values = self::dedupeStrings($tools);
        if ($values === []) {
            $this->setData(self::ALLOWED_TOOLS_JSON, null);
            return $this;
        }
        $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->setData(self::ALLOWED_TOOLS_JSON, $encoded);
        return $this;
    }

    /**
     * @return AuthMode
     */
    public function getAuthMode(): AuthMode
    {
        return AuthMode::fromStorage($this->getDataStringOrNull(self::AUTH_MODE));
    }

    /**
     * @param AuthMode $mode
     * @return self
     */
    public function setAuthMode(AuthMode $mode): self
    {
        $this->setData(self::AUTH_MODE, $mode->value);
        return $this;
    }

    /**
     * @return int|null
     */
    public function getServiceAdminUserId(): ?int
    {
        return $this->getDataIntOrNull(self::SERVICE_ADMIN_USER_ID, cast: true);
    }

    /**
     * @param int|null $adminUserId
     * @return self
     */
    public function setServiceAdminUserId(?int $adminUserId): self
    {
        $this->setData(self::SERVICE_ADMIN_USER_ID, $adminUserId);
        return $this;
    }

    /**
     * @return array<int, int>
     */
    public function getAllowedAdminUserIds(): array
    {
        return self::decodeIntList($this->getDataStringOrNull(self::ALLOWED_ADMIN_USER_IDS_JSON));
    }

    /**
     * @param array<int, int> $userIds
     * @return self
     */
    public function setAllowedAdminUserIds(array $userIds): self
    {
        $this->setData(self::ALLOWED_ADMIN_USER_IDS_JSON, self::encodeIntList($userIds));
        return $this;
    }

    /**
     * @return array<int, int>
     */
    public function getAllowedAdminRoleIds(): array
    {
        return self::decodeIntList($this->getDataStringOrNull(self::ALLOWED_ADMIN_ROLE_IDS_JSON));
    }

    /**
     * @param array<int, int> $roleIds
     * @return self
     */
    public function setAllowedAdminRoleIds(array $roleIds): self
    {
        $this->setData(self::ALLOWED_ADMIN_ROLE_IDS_JSON, self::encodeIntList($roleIds));
        return $this;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        $raw = $this->getData(self::DISABLED);
        return is_scalar($raw) && (int) $raw === 1;
    }

    /**
     * @param bool $disabled
     * @return self
     */
    public function setDisabled(bool $disabled): self
    {
        $this->setData(self::DISABLED, $disabled ? 1 : 0);
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
     * @param string|null $json
     * @return array<int, string>
     */
    private static function decodeStringList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            $decoded,
            static fn (mixed $v): bool => is_string($v) && $v !== ''
        ));
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, string>
     */
    private static function dedupeStrings(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
    }

    /**
     * @param string|null $json
     * @return array<int, int>
     */
    private static function decodeIntList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($decoded as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                continue;
            }
            $int = (int) $value;
            if ($int <= 0 || isset($seen[$int])) {
                continue;
            }
            $seen[$int] = true;
            $out[] = $int;
        }
        return $out;
    }

    /**
     * @param array<int, int> $values
     * @return string|null
     */
    private static function encodeIntList(array $values): ?string
    {
        $clean = [];
        $seen = [];
        foreach ($values as $value) {
            if ($value <= 0 || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $clean[] = $value;
        }
        if ($clean === []) {
            return null;
        }
        return json_encode($clean, JSON_THROW_ON_ERROR);
    }
}
