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
}
