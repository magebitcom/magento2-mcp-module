<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\Component\Listing\Column;

/**
 * Shared narrowing helper for `Escaper::escapeHtml()` / `escapeUrl()`
 * callers.
 *
 * Magento's Escaper declares a `string|array` return type — a leftover from
 * its ability to walk arrays of strings. Every MCP grid column feeds it a
 * single string, so the return is always a string in practice; this helper
 * narrows without tripping PHPStan level 9.
 */
class HtmlEscape
{
    /**
     * Utility-only; never instantiated.
     *
     * @codingStandardsIgnoreStart
     */
    private function __construct()
    {
        // No-op constructor — kept private to prevent instantiation.
    }
    // @codingStandardsIgnoreEnd

    /**
     * Narrow `string|array` down to a string, dropping arrays as empty text.
     *
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
