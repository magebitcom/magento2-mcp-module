<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * MCP tool `system.store.list` — enumerate the storefront topology.
 *
 * Every other MCP tool that filters by `store_id` / `website_id` relies on
 * the caller knowing these identifiers; surfacing them as a tool lets the
 * AI client discover the catalog of scopes without an out-of-band lookup.
 * Read-only and exposes no PII.
 */
class StoreList implements ToolInterface
{
    public const TOOL_NAME = 'system.store.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_store_list';

    /**
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param GroupRepositoryInterface $groupRepository
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Stores & Websites';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Enumerate every website, store group, and store view on this '
            . 'Magento instance. Use the returned ids to scope other tools '
            . '(e.g. filter `sales.order.list` by `store_id`). Admin-scope id '
            . '`0` is excluded. Optionally narrow the tree to one or more '
            . 'websites with `website_id`.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include stores with `is_active=0`. Defaults to `false`.',
                ],
                'website_id' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'minItems' => 1,
                    'description' => 'Narrow the output to these website ids '
                        . '(e.g. `[1]` for a single website). Groups and stores '
                        . 'belonging to other websites are dropped.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $includeInactive = (bool) ($arguments['include_inactive'] ?? false);
        $websiteFilter = $this->resolveWebsiteFilter($arguments['website_id'] ?? null);

        $websites = [];
        foreach ($this->websiteRepository->getList() as $website) {
            $websiteId = (int) $website->getId();
            if ($websiteId === 0) {
                continue;
            }
            if ($websiteFilter !== null && !isset($websiteFilter[$websiteId])) {
                continue;
            }
            $websites[] = $this->formatWebsite($website);
        }

        $groups = [];
        foreach ($this->groupRepository->getList() as $group) {
            if ((int) $group->getId() === 0) {
                continue;
            }
            if ($websiteFilter !== null && !isset($websiteFilter[(int) $group->getWebsiteId()])) {
                continue;
            }
            $groups[] = $this->formatGroup($group);
        }

        $stores = [];
        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            if (!$includeInactive && !$store->getIsActive()) {
                continue;
            }
            if ($websiteFilter !== null && !isset($websiteFilter[(int) $store->getWebsiteId()])) {
                continue;
            }
            $stores[] = $this->formatStore($store);
        }

        $payload = [
            'websites' => $websites,
            'groups' => $groups,
            'stores' => $stores,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode store list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'website_count' => count($websites),
                'group_count' => count($groups),
                'store_count' => count($stores),
            ]
        );
    }

    /**
     * Normalise the optional `website_id` argument into a lookup map.
     *
     * Returns an `int => true` map (or null when the caller did not pass
     * anything) so the three loops above can test membership in O(1).
     *
     * @param mixed $raw
     * @return array<int, true>|null
     * @throws LocalizedException
     */
    private function resolveWebsiteFilter(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $ids = [];
        if (is_int($raw) && $raw > 0) {
            $ids[] = $raw;
        } elseif (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_int($entry) && $entry > 0) {
                    $ids[] = $entry;
                }
            }
        }
        if ($ids === []) {
            throw new LocalizedException(
                __('Filter "website_id" requires a positive integer or array of integers.')
            );
        }
        return array_fill_keys($ids, true);
    }

    /**
     * Shape a website for the response payload.
     *
     * @param WebsiteInterface $website
     * @return array<string, mixed>
     */
    private function formatWebsite(WebsiteInterface $website): array
    {
        return [
            'id' => (int) $website->getId(),
            'code' => (string) $website->getCode(),
            'name' => (string) $website->getName(),
            'default_group_id' => (int) $website->getDefaultGroupId(),
        ];
    }

    /**
     * Shape a store group for the response payload.
     *
     * @param GroupInterface $group
     * @return array<string, mixed>
     */
    private function formatGroup(GroupInterface $group): array
    {
        return [
            'id' => (int) $group->getId(),
            'code' => (string) $group->getCode(),
            'name' => (string) $group->getName(),
            'website_id' => (int) $group->getWebsiteId(),
            'root_category_id' => (int) $group->getRootCategoryId(),
            'default_store_id' => (int) $group->getDefaultStoreId(),
        ];
    }

    /**
     * Shape a store view for the response payload.
     *
     * @param StoreInterface $store
     * @return array<string, mixed>
     */
    private function formatStore(StoreInterface $store): array
    {
        return [
            'id' => (int) $store->getId(),
            'code' => (string) $store->getCode(),
            'name' => (string) $store->getName(),
            'website_id' => (int) $store->getWebsiteId(),
            'group_id' => (int) $store->getStoreGroupId(),
            'is_active' => (bool) $store->getIsActive(),
        ];
    }
}
