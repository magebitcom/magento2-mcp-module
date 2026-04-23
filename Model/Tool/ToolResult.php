<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\ToolResultInterface;

/**
 * Immutable default implementation of {@see ToolResultInterface}.
 *
 * Has no dependencies — tools instantiate it directly (no factory / ObjectManager).
 */
final class ToolResult implements ToolResultInterface
{
    /**
     * @param array<int, array<string, mixed>> $content       MCP content blocks.
     * @param array<string, mixed>             $auditSummary  PII-free summary for audit log.
     */
    public function __construct(
        private readonly array $content,
        private readonly bool $isError = false,
        private readonly array $auditSummary = []
    ) {
    }

    /**
     * Shortcut for the common "single text block" case.
     *
     * @param array<string, mixed> $auditSummary
     */
    public static function text(string $text, array $auditSummary = []): self
    {
        return new self(
            content: [['type' => 'text', 'text' => $text]],
            auditSummary: $auditSummary
        );
    }

    public function getContent(): array
    {
        return $this->content;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function getAuditSummary(): array
    {
        return $this->auditSummary;
    }
}
