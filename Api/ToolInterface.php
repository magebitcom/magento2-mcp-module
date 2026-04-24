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
     * Natural-language description consumed by the AI client.
     *
     * Keep it specific, action-oriented, and argument-aware so the model picks
     * the right tool without extra prompting.
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing the tool's accepted arguments.
     *
     * Validated against the client-supplied `params.arguments` before
     * {@see execute()} is called.
     *
     * Prefer building the schema with {@see \Magebit\Mcp\Model\Tool\Schema\Schema}
     * over returning a hand-written array — the builder locks in the MCP-
     * required invariants (draft-07, type=object, additionalProperties=false,
     * no oneOf/anyOf/allOf) and catches typos at author time. Raw arrays
     * remain supported as an escape hatch for rare keywords the builder
     * does not cover.
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
     * Hint that the AI client should prompt the user for explicit confirmation.
     *
     * Useful for destructive or expensive reads.
     */
    public function getConfirmationRequired(): bool;

    /**
     * Invoke the tool with validated arguments.
     *
     * @param array $arguments Already validated against getInputSchema().
     * @phpstan-param array<string, mixed> $arguments
     * @return ToolResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException on expected errors.
     */
    public function execute(array $arguments): ToolResultInterface;
}
