<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Url;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the public-facing base URL used by the OAuth discovery documents
 * and the `WWW-Authenticate` challenge.
 *
 * Lookup order:
 *  1. The `magebit_mcp/general/public_base_url` config override, if set.
 *     Intentionally not exposed in admin Stores → Configuration — operators
 *     set it via `app/etc/env.php` when running behind a tunnel/proxy that
 *     preserves the upstream `Host` (the canonical ngrok behavior, where the
 *     real public hostname only appears in `X-Forwarded-Host`).
 *  2. Otherwise the current store's `web/secure/base_url`.
 *
 * The returned base never has a trailing slash, so callers can append paths
 * with a single concatenation.
 */
class PublicUrlBuilder
{
    /**
     * @param ModuleConfig $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string
     */
    public function getBaseUrl(): string
    {
        $override = $this->config->getPublicBaseUrl();
        if ($override !== null) {
            return $override;
        }

        return rtrim(
            (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB),
            '/'
        );
    }

    /**
     * Concatenate `$path` onto the resolved base URL. A leading slash on
     * `$path` is optional — both forms produce the same result.
     *
     * @param string $path
     * @return string
     */
    public function buildUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $this->getBaseUrl() . $path;
    }
}
