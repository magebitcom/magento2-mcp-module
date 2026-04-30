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

    /**
     * Resolves any accepted-on-the-wire form (canonical dotted name OR the
     * dot→underscore wire form Claude.ai accepts) back to the canonical name
     * `getName()` returns. Used by the JSON-RPC dispatcher so downstream code
     * (ACL, scopes, audit) only ever sees the canonical identity.
     *
     * @param string $name
     * @return string|null canonical name, or null when neither form resolves.
     */
    public function getCanonicalName(string $name): ?string;
}
