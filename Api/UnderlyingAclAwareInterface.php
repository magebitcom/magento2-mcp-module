<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Opt-in second ACL check for tools wrapping a Magento service contract. Enforces the invariant "MCP cannot do what the admin UI cannot" — both the MCP-specific and underlying Magento resources must pass, else -32004 FORBIDDEN.
 */
interface UnderlyingAclAwareInterface
{
    /**
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string;
}
