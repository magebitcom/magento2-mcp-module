<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Registry of all MCP tools known to the module.
 *
 * Populated at DI compile time via `<argument name="tools" xsi:type="array">`
 * on the registry's constructor. 3rd-party modules append to this array from
 * their own etc/di.xml without touching Magebit_Mcp code.
 */
interface ToolRegistryInterface
{
    /**
     * All registered tools, keyed by tool name.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array;

    public function has(string $name): bool;

    /**
     * @throws NoSuchEntityException when the tool is not registered.
     */
    public function get(string $name): ToolInterface;
}
