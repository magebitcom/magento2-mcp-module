<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Tool result that also carries machine-readable structured content next to the text blocks.
 */
interface StructuredToolResultInterface extends ToolResultInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getStructuredContent(): ?array;
}
