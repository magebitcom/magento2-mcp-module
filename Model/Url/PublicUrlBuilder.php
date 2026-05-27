<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Url;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class PublicUrlBuilder
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string
     */
    public function getBaseUrl(): string
    {
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

    /**
     * Scheme + host + optional port of the base URL, with no path component.
     * RFC 8414 §3 / RFC 9728 §3.1 insert `.well-known` directly after this.
     *
     * @return string
     */
    public function getAuthority(): string
    {
        $base = $this->getBaseUrl();
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $base;
        }

        $authority = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    /**
     * Path component of the base URL, trimmed of slashes. Empty for a root
     * install, e.g. `lv` for https://example.com/lv/.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        $parts = parse_url($this->getBaseUrl());
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '';

        return trim($path, '/');
    }

    /**
     * The RFC 9728 resource identifier — what the MCP client targets.
     *
     * @return string
     */
    public function getResourceUrl(): string
    {
        return $this->buildUrl('/mcp');
    }

    /**
     * Build a spec-compliant well-known URL by inserting `.well-known/<name>`
     * between the authority and the resource/issuer path.
     *
     * @param string $name
     * @param string $resourcePath
     * @return string
     */
    public function getWellKnownUrl(string $name, string $resourcePath = ''): string
    {
        $url = $this->getAuthority() . '/.well-known/' . trim($name, '/');
        $suffix = trim($resourcePath, '/');
        if ($suffix !== '') {
            $url .= '/' . $suffix;
        }

        return $url;
    }

    /**
     * @return string
     */
    public function getProtectedResourceWellKnownUrl(): string
    {
        $path = trim($this->getBasePath() . '/mcp', '/');

        return $this->getWellKnownUrl('oauth-protected-resource', $path);
    }

    /**
     * @return string
     */
    public function getAuthServerWellKnownUrl(): string
    {
        return $this->getWellKnownUrl('oauth-authorization-server', $this->getBasePath());
    }
}
