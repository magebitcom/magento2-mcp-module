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
 * "Show me my stores" — parameterless orientation prompt. Useful first
 * action after a fresh OAuth connection so the operator can confirm "yes,
 * this is the right store" before doing anything else.
 */
class ListStoresPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'system.list_stores';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Show me my stores';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists every store and website with its public address, language, and currency.';
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
                Call system.store.list. Format the result as a friendly Website → Store → Store View nesting, where each view shows:
                - public address (the customer-facing URL)
                - default language
                - default currency

                Use the `name` field, not internal codes. If there's only one store and one view, present it as one short paragraph instead of a nested list — don't over-structure a small store. Don't include internal id numbers in the output unless the user asks for them.
                TXT
            ),
        ];
    }
}
