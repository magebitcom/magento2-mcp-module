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
 * "I just made changes — make them visible" — parameterless write workflow.
 *
 * Filtered out of `prompts/list` for tokens / installs that can't write, so
 * a read-only operator never sees an option that would error on selection.
 */
class RefreshAfterEditPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'system.refresh_after_edit';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'I just made changes — make them visible';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return "Refreshes your store after you've edited products, prices, or settings, so customers see the changes.";
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
        return true;
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
                The user has just edited products, prices, or settings and wants those changes to become visible to customers.

                Do this in order, narrating each step in one short plain-English sentence (avoid the words "cache", "indexer", "FPC", "flush" in your output to the user):
                1. Call system.cache.flush with no arguments (flushes everything).
                2. Call system.index.list, then for each indexer whose status indicates it needs a reindex, call system.index.reindex with that indexer id.

                Finish with one sentence ("Your changes are live now."). If a step fails, describe what failed in plain language and stop — don't continue past a failure.
                TXT
            ),
        ];
    }
}
