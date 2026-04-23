<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Model\Tool\WriteMode;

/**
 * Contract every MCP tool must implement.
 *
 * Tools are registered with the {@see ToolRegistryInterface} via DI array injection
 * (see etc/di.xml) and gated by their own dedicated ACL resource declared in acl.xml.
 */
interface ToolInterface
{
    /**
     * MCP / JSON-RPC tool identifier, e.g. "sales.order.get".
     *
     * Must match ^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$ and be globally unique across
     * all registered tools. Collisions fail at ToolRegistry construction.
     */
    public function getName(): string;

    /**
     * Human-readable title shown in admin tooling (not the AI client).
     */
    public function getTitle(): string;

    /**
     * Natural-language description consumed by the AI client when deciding whether
     * to invoke this tool. Keep it specific, action-oriented, and argument-aware.
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing the tool's accepted arguments. Validated against the
     * client-supplied `params.arguments` before {@see execute()} is called.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Magento ACL resource ID gating this tool, e.g. "Magebit_Mcp::tool.sales.order.get".
     *
     * MUST match a resource declared in some module's acl.xml nested under
     * Magebit_Mcp::tools. Validated at compile time by the
     * `magebit:mcp:tools:validate-acl` console command.
     */
    public function getAclResource(): string;

    /**
     * Whether this tool reads or mutates data.
     *
     * WRITE tools require both the global `magebit_mcp/general/allow_writes`
     * config AND the token's `allow_writes` flag to be true.
     */
    public function getWriteMode(): WriteMode;

    /**
     * Hint to the AI client that it should prompt the user for explicit confirmation
     * before invoking this tool (e.g. destructive or expensive reads).
     */
    public function getConfirmationRequired(): bool;

    /**
     * Invoke the tool with validated arguments.
     *
     * @param array<string, mixed> $arguments Already validated against getInputSchema().
     * @throws \Magento\Framework\Exception\LocalizedException on expected errors.
     */
    public function execute(array $arguments): ToolResultInterface;
}
