<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Result returned by ToolInterface::execute(). Carries MCP content blocks for the client and a PII-free audit summary; never put PII or full response bodies in the summary.
 */
interface ToolResultInterface
{
    /**
     * MCP content blocks; each has at minimum a "type" key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getContent(): array;

    /**
     * Soft-error flag; surfaces as `isError: true` in the MCP response.
     */
    public function isError(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getAuditSummary(): array;
}
