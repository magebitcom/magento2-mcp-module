<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

/**
 * Narrows Magento Escaper's `string|array` return down to `string` — MCP
 * grid columns only ever pass single strings, so the array branch never
 * fires in practice, and this keeps PHPStan level 9 happy.
 */
class HtmlEscape
{
    /**
     * @codingStandardsIgnoreStart
     */
    private function __construct()
    {
    }
    // @codingStandardsIgnoreEnd

    /**
     * @param string|array $escaped
     * @phpstan-param string|array<int|string, mixed> $escaped
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function toString(string|array $escaped): string
    {
        return is_string($escaped) ? $escaped : '';
    }
}
