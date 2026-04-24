<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool;

use Magebit\Mcp\Api\ToolResultInterface;

/**
 * Immutable default implementation of {@see ToolResultInterface}.
 */
class ToolResult implements ToolResultInterface
{
    /**
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
     * @phpstan-param array<string, mixed> $auditSummary
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
