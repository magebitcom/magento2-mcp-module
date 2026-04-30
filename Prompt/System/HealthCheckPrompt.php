<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Prompt\System;

use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

/**
 * "Is my store healthy?" — parameterless triage prompt.
 *
 * The body explicitly forbids the LLM from using the words "cache",
 * "indexer", "notification" in its user-facing reply, since the audience is
 * a store owner / staff member, not a Magento dev.
 */
class HealthCheckPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'system.health_check';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Is my store healthy?';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return "Quick check of your store's caches, data refresh state, and admin alerts. "
            . 'Reports any issues in plain language.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getRequiresWrite(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return [
            new PromptMessage(
                PromptMessage::ROLE_USER,
                <<<TXT
                The user wants to know if their store has any issues that could affect customers or admins.

                Run these checks (in parallel where possible):
                1. Call system.cache.list — note any cache type whose status is disabled.
                2. Call system.index.list — note any indexer whose status indicates a reindex is required.
                3. Call system.notification.list — note any unread notification at severity "critical" or "major".

                Reply in 1–2 short paragraphs (no bullet list unless there are 4+ issues) using store-owner language only. Translate technical terms:
                - "cache disabled" → "page speed feature turned off"
                - "indexer invalid / reindex required" → "product or category data hasn't refreshed yet"
                - "critical notification" → "important alert"

                If everything is clean, say so in one sentence. If there are issues, name them concretely and tell the user whether they're urgent or just notable. Don't suggest fixes unless asked.
                TXT
            ),
        ];
    }
}
