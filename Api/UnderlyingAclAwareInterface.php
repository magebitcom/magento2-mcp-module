<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Optional opt-in for tools that wrap a Magento service contract behind
 * their own MCP-scoped ACL.
 *
 * A tool that delegates to, say, `InvoiceOrderInterface::execute()` SHOULD
 * return `Magento_Sales::invoice` here. The `ToolsCallHandler` then enforces
 * a secondary ACL check before calling `execute()`, giving the invariant
 * "MCP cannot do what the admin UI cannot":
 *   - the MCP-specific resource ({@see ToolInterface::getAclResource()}) gates
 *     whether this admin can reach the tool at all via MCP;
 *   - the underlying Magento resource returned here gates whether this admin
 *     could perform the same action via the admin UI.
 *
 * Both must pass; either denial yields a `-32004 FORBIDDEN` response.
 *
 * Tools that are pure reads of MCP-module-local data, or that have no
 * meaningful underlying Magento resource, SHOULD NOT implement this
 * interface.
 */
interface UnderlyingAclAwareInterface
{
    /**
     * Magento ACL resource the tool delegates to (or null to skip the secondary check).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string;
}
