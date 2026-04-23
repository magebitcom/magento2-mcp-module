<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Result returned by {@see ToolInterface::execute()}.
 *
 * Carries two distinct payloads:
 * - content: MCP content blocks sent to the AI client (may contain full response body)
 * - audit summary: a redacted, size-bounded summary persisted in magebit_mcp_audit_log
 *   Never put PII or full response bodies in the audit summary.
 */
interface ToolResultInterface
{
    /**
     * MCP content blocks. Each block is an associative array with at minimum a
     * "type" key (e.g. "text", "image", "resource"). For text content:
     * ["type" => "text", "text" => "..."].
     *
     * @return array<int, array<string, mixed>>
     */
    public function getContent(): array;

    /**
     * Whether the tool reports this invocation as an error (sets `isError: true`
     * in the MCP response). Hard exceptions thrown from execute() bypass this
     * and surface as JSON-RPC errors; use isError() for soft/expected errors
     * the AI should see and recover from.
     */
    public function isError(): bool;

    /**
     * Compact, PII-free summary safe for the audit log.
     *
     * @return array<string, mixed>
     */
    public function getAuditSummary(): array;
}
