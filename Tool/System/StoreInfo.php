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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreConfigManagerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * MCP tool `system.store.info` — return branding + URLs for one store view.
 *
 * Sources:
 * - {@see StoreConfigManagerInterface::getStoreConfigs()} for locale, currency,
 *   timezone, weight unit, and the full matrix of base URLs (secure / unsecure
 *   / link / media / static).
 * - Scope-config reads under `general/store_information/*` for the merchant
 *   profile an admin enters in Stores → Configuration → General → Store Information
 *   (name, phone, hours, street, city, country, postcode, VAT number).
 *
 * Read-only and exposes no PII beyond what the merchant already publishes as
 * their store contact info.
 */
class StoreInfo implements ToolInterface
{
    public const TOOL_NAME = 'system.store.info';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_store_info';

    private const STORE_INFORMATION_KEYS = [
        'name' => 'general/store_information/name',
        'phone' => 'general/store_information/phone',
        'hours' => 'general/store_information/hours',
        'country_id' => 'general/store_information/country_id',
        'region_id' => 'general/store_information/region_id',
        'postcode' => 'general/store_information/postcode',
        'city' => 'general/store_information/city',
        'street_line1' => 'general/store_information/street_line1',
        'street_line2' => 'general/store_information/street_line2',
        'vat_number' => 'general/store_information/merchant_vat_number',
    ];

    /**
     * @param StoreRepositoryInterface $storeRepository
     * @param StoreConfigManagerInterface $storeConfigManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly StoreConfigManagerInterface $storeConfigManager,
        private readonly ScopeConfigInterface $scopeConfig
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
        return 'Get Store Information';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Return the general-settings profile for a single store view: '
            . 'name, base URLs (secure/unsecure, media, static), locale, '
            . 'timezone, currencies, and the merchant contact block '
            . '(phone, address, hours, VAT number).';
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
                'store_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numeric store view id (see `system.store.list`).',
                ],
                'store_code' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Store-view code (e.g. `default`).',
                ],
            ],
            'oneOf' => [
                ['required' => ['store_id']],
                ['required' => ['store_code']],
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
        $store = $this->resolveStore($arguments);

        $config = $this->storeConfigForCode((string) $store->getCode());

        $payload = [
            'store' => [
                'id' => (int) $store->getId(),
                'code' => (string) $store->getCode(),
                'name' => (string) $store->getName(),
                'website_id' => (int) $store->getWebsiteId(),
                'group_id' => (int) $store->getStoreGroupId(),
                'is_active' => (bool) $store->getIsActive(),
            ],
            'locale' => [
                'code' => (string) $config->getLocale(),
                'timezone' => (string) $config->getTimezone(),
                'weight_unit' => (string) $config->getWeightUnit(),
            ],
            'currency' => [
                'base' => (string) $config->getBaseCurrencyCode(),
                'default_display' => (string) $config->getDefaultDisplayCurrencyCode(),
            ],
            'urls' => [
                'base_url' => (string) $config->getBaseUrl(),
                'base_link_url' => (string) $config->getBaseLinkUrl(),
                'base_static_url' => (string) $config->getBaseStaticUrl(),
                'base_media_url' => (string) $config->getBaseMediaUrl(),
                'secure_base_url' => (string) $config->getSecureBaseUrl(),
                'secure_base_link_url' => (string) $config->getSecureBaseLinkUrl(),
                'secure_base_static_url' => (string) $config->getSecureBaseStaticUrl(),
                'secure_base_media_url' => (string) $config->getSecureBaseMediaUrl(),
            ],
            'store_information' => $this->readStoreInformation((int) $store->getId()),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode store info as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'store_id' => (int) $store->getId(),
                'store_code' => (string) $store->getCode(),
            ]
        );
    }

    /**
     * Resolve the target store from the caller's arguments.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return StoreInterface
     * @throws LocalizedException
     */
    private function resolveStore(array $arguments): StoreInterface
    {
        try {
            $rawId = $arguments['store_id'] ?? null;
            if (is_numeric($rawId)) {
                return $this->storeRepository->getById((int) $rawId);
            }
            $rawCode = $arguments['store_code'] ?? null;
            if (is_string($rawCode) && $rawCode !== '') {
                return $this->storeRepository->get($rawCode);
            }
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Store not found: %1', $e->getMessage()));
        }

        throw new LocalizedException(__('Either "store_id" or "store_code" is required.'));
    }

    /**
     * Load the API-side store config bundle (base URLs, locale, currency).
     *
     * @param string $code
     * @return StoreConfigInterface
     * @throws LocalizedException
     */
    private function storeConfigForCode(string $code): StoreConfigInterface
    {
        $configs = $this->storeConfigManager->getStoreConfigs([$code]);
        $config = $configs[0] ?? null;
        if (!$config instanceof StoreConfigInterface) {
            throw new LocalizedException(__('Store config unavailable for "%1".', $code));
        }
        return $config;
    }

    /**
     * Collect the merchant-profile config block at store scope.
     *
     * @param int $storeId
     * @return array<string, string|null>
     */
    private function readStoreInformation(int $storeId): array
    {
        $result = [];
        foreach (self::STORE_INFORMATION_KEYS as $key => $path) {
            $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
            $result[$key] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }
        return $result;
    }
}
