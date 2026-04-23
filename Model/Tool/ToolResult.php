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
class ToolResult implements ToolResultInterface
{
    /**
     * @param array $content MCP content blocks.
     * @param bool $isError
     * @param array $auditSummary PII-free summary for audit log.
     * @phpstan-param array<int, array<string, mixed>> $content
     * @phpstan-param array<string, mixed> $auditSummary
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
     * @param string $text
     * @param array $auditSummary
     * @phpstan-param array<string, mixed> $auditSummary
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function text(string $text, array $auditSummary = []): self
    {
        return new self(
            content: [['type' => 'text', 'text' => $text]],
            auditSummary: $auditSummary
        );
    }

    /**
     * @inheritDoc
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @inheritDoc
     */
    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * @inheritDoc
     */
    public function getAuditSummary(): array
    {
        return $this->auditSummary;
    }
}
