<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Registry of all MCP tools, populated via DI array injection at compile time.
 */
interface ToolRegistryInterface
{
    /**
     * @return array<string, ToolInterface>
     */
    public function all(): array;

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * @param string $name
     * @return ToolInterface
     * @throws NoSuchEntityException when the tool is not registered.
     */
    public function get(string $name): ToolInterface;
}
