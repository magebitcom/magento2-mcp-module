<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Tool\WriteMode;

/**
 * Metadata shared by every tool kind (plain and bulk): identity, ACL and write gating.
 */
interface ToolDescriptorInterface
{
    /**
     * MCP / JSON-RPC tool identifier; must match ^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$ and be globally unique.
     */
    public function getName(): string;

    /**
     * Human-readable title for admin tooling.
     */
    public function getTitle(): string;

    /**
     * Natural-language description consumed by the AI client.
     */
    public function getDescription(): string;

    /**
     * Magento ACL resource ID gating this tool; must resolve under Magebit_Mcp::tools.
     */
    public function getAclResource(): string;

    /**
     * WRITE tools require both global allow_writes config AND the token's allow_writes flag.
     */
    public function getWriteMode(): WriteMode;

    /**
     * Hint that the AI client should prompt for explicit user confirmation.
     */
    public function getConfirmationRequired(): bool;
}
