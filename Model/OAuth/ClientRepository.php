<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Model\OAuth\ResourceModel\Client as ClientResource;
use Magebit\Mcp\Model\OAuth\ResourceModel\Client\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * CRUD + lookup service for {@see Client} entities.
 *
 * {@see self::getByClientId()} relies on the UNIQUE index on `client_id` for O(1) lookup.
 */
class ClientRepository
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ClientResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(Client $client): Client
    {
        $this->resource->save($client);
        return $client;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): Client
    {
        $client = $this->clientFactory->create();
        $this->resource->load($client, $id);
        if ($client->getId() === null) {
            throw NoSuchEntityException::singleField('id', $id);
        }
        return $client;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getByClientId(string $clientId): Client
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('client_id', ['eq' => $clientId]);
        /** @var Client $client */
        $client = $collection->getFirstItem();
        if ($client->getId() === null) {
            throw NoSuchEntityException::singleField('client_id', $clientId);
        }
        return $client;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function deleteById(int $id): void
    {
        $client = $this->getById($id);
        $this->resource->delete($client);
    }

    /**
     * @return array<int, Client>
     */
    public function getList(): array
    {
        $collection = $this->collectionFactory->create();
        return $this->narrowItems($collection->getItems());
    }

    /**
     * @param array $ids
     * @phpstan-param array<int, int> $ids
     * @return array<int, Client>
     */
    public function listByIds(array $ids): array
    {
        $filtered = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($filtered === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('id', ['in' => $filtered]);

        $out = [];
        foreach ($this->narrowItems($collection->getItems()) as $client) {
            $id = $client->getId();
            if ($id === null) {
                continue;
            }
            $out[$id] = $client;
        }
        return $out;
    }

    /**
     * @param array $items
     * @phpstan-param array<int|string, mixed> $items
     * @return array<int, Client>
     */
    private function narrowItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof Client) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
