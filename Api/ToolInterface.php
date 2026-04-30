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
 * Contract every MCP tool must implement. Registered via DI array into ToolRegistryInterface and gated by an ACL resource.
 */
interface ToolInterface
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
     * JSON Schema for arguments; validated before execute().
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

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

    /**
     * @param array $arguments Already validated against getInputSchema().
     * @phpstan-param array<string, mixed> $arguments
     * @return ToolResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException on expected errors.
     */
    public function execute(array $arguments): ToolResultInterface;
}
