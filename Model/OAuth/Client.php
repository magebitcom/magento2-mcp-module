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
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record OAuth client. Redirect URIs round-trip as a plain PHP array.
 */
class Client extends AbstractModel implements ClientInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(ClientResource::class);
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
    public function getClientId(): string
    {
        $v = $this->getData(self::CLIENT_ID);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setClientId(string $clientId): self
    {
        $this->setData(self::CLIENT_ID, $clientId);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getClientSecretHash(): string
    {
        $v = $this->getData(self::CLIENT_SECRET_HASH);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @inheritDoc
     */
    public function setClientSecretHash(string $hash): self
    {
        $this->setData(self::CLIENT_SECRET_HASH, $hash);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $v = $this->getData(self::NAME);
        return is_scalar($v) ? (string) $v : '';
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
    public function getRedirectUris(): array
    {
        $raw = $this->getData(self::REDIRECT_URIS_JSON);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $uris = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $uris[] = $item;
            }
        }
        return $uris;
    }

    /**
     * @inheritDoc
     */
    public function setRedirectUris(array $uris): self
    {
        $encoded = json_encode(array_values($uris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->setData(self::REDIRECT_URIS_JSON, $encoded);
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
}
