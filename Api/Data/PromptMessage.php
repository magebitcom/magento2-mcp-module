<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data;

/**
 * Single message in a {@see \Magebit\Mcp\Api\PromptInterface} body.
 *
 * Per MCP spec 2025-06-18 a prompt's `messages[]` wraps each text payload as
 * `{role, content: {type: 'text', text: '…'}}`. We deliberately model only the
 * text variant — image / audio / resource embeds are out of scope for the
 * current iteration.
 */
class PromptMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param string $role Either `user` or `assistant` (per MCP spec).
     * @param string $text The text payload — `{{name}}` placeholders are
     *                     substituted by {@see \Magebit\Mcp\Model\Prompt\PromptRenderer}.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $text
    ) {
    }
}
