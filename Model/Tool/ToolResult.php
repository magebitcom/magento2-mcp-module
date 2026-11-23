<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\StructuredToolResultInterface;

/**
 * Immutable default implementation of {@see StructuredToolResultInterface}.
 */
class ToolResult implements StructuredToolResultInterface
{
    /**
     * @param array<int, array<string, mixed>> $content
     * @param bool $isError
     * @param array<string, mixed> $auditSummary
     * @param array<string, mixed>|null $structuredContent
     * @return void
     */
    public function __construct(
        private readonly array $content,
        private readonly bool $isError = false,
        private readonly array $auditSummary = [],
        private readonly ?array $structuredContent = null
    ) {
    }

    /**
     * @param string $text
     * @param array<string, mixed> $auditSummary
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

    /**
     * @inheritDoc
     */
    public function getStructuredContent(): ?array
    {
        return $this->structuredContent;
    }
}
