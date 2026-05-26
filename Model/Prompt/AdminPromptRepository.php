<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt as AdminPromptResource;
use Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * CRUD + lookup service for {@see AdminPrompt} entities.
 */
class AdminPromptRepository
{
    /**
     * @param AdminPromptFactory $promptFactory
     * @param AdminPromptResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly AdminPromptFactory $promptFactory,
        private readonly AdminPromptResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param AdminPrompt $prompt
     * @return AdminPrompt
     */
    public function save(AdminPrompt $prompt): AdminPrompt
    {
        $this->resource->save($prompt);
        return $prompt;
    }

    /**
     * @param int $id
     * @return AdminPrompt
     * @throws NoSuchEntityException
     */
    public function getById(int $id): AdminPrompt
    {
        $prompt = $this->promptFactory->create();
        $this->resource->load($prompt, $id);
        if ($prompt->getId() === null) {
            throw NoSuchEntityException::singleField('id', $id);
        }
        return $prompt;
    }

    /**
     * @param string $name
     * @return AdminPrompt
     * @throws NoSuchEntityException
     */
    public function getByName(string $name): AdminPrompt
    {
        $prompt = $this->promptFactory->create();
        $this->resource->load($prompt, $name, 'name');
        if ($prompt->getId() === null) {
            throw NoSuchEntityException::singleField('name', $name);
        }
        return $prompt;
    }

    /**
     * @param int $id
     * @return void
     * @throws NoSuchEntityException
     */
    public function deleteById(int $id): void
    {
        $prompt = $this->getById($id);
        $this->resource->delete($prompt);
    }

    /**
     * @return array<int, AdminPrompt>
     */
    public function getList(): array
    {
        $collection = $this->collectionFactory->create();
        return $this->narrowItems($collection->getItems());
    }

    /**
     * Active rows only. Used by {@see AdminPromptProvider} to feed the registry —
     * inactive rows are kept in the DB so admins can re-enable later but stay
     * invisible to MCP clients.
     *
     * @return array<int, AdminPrompt>
     */
    public function getActive(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        return $this->narrowItems($collection->getItems());
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, AdminPrompt>
     */
    private function narrowItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof AdminPrompt) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
