<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Prompt\System;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

/**
 * "Find a setting" — single-argument lookup prompt. The user describes the
 * setting in plain English; the LLM maps it to the right Magento config
 * path and reports the current value.
 */
class FindSettingPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'system.find_setting';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Find a setting';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return "Describe a setting in your own words and I'll look up its current value.";
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            new PromptArgument(
                name: 'query',
                description: "What are you looking for? (e.g. 'the email order confirmations come from')",
                required: true
            ),
        ];
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
                The user is looking for a Magento configuration setting and described it in their own words: "{{query}}"

                Map this to the most likely config path (e.g. trans_email/ident_general/email, web/secure/base_url, general/locale/code — section/group/field shape).

                If you're confident which path the user means:
                  1. Call system.config.get with that path at the relevant scope (default scope unless the request mentions a specific store).
                  2. Report the value plainly: "Order confirmations come from billing@example.com."

                If you're not confident or there are multiple plausible matches:
                  1. List 2–3 candidate matches in plain English with the underlying setting they correspond to.
                  2. Ask which one the user wants before calling any tool.

                Never guess at a path — if no candidate is plausible, tell the user you couldn't find a matching setting and ask them to describe it differently.
                TXT
            ),
        ];
    }
}
