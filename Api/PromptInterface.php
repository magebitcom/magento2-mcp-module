<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;

/**
 * Contract every MCP prompt must implement. Registered via DI array into
 * {@see PromptRegistryInterface}. Names match the same regex as tools
 * (`^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`) and the di.xml key must equal
 * `getName()` — both checks run at registry construction.
 *
 * Prompts are LLM-facing templates, not handlers. `getMessages()` returns the
 * literal text pre-substitution; the dispatcher's renderer fills in any
 * `{{argument_name}}` placeholders against the request arguments.
 */
interface PromptInterface
{
    /**
     * Canonical MCP / JSON-RPC prompt identifier.
     */
    public function getName(): string;

    /**
     * Store-owner-friendly label shown in the MCP client's prompt menu.
     * The audience is non-technical — avoid jargon ("cache", "indexer", etc.).
     */
    public function getTitle(): string;

    /**
     * One-line plain-English helper text shown under the title.
     */
    public function getDescription(): string;

    /**
     * Inputs the prompt accepts. Empty array = parameterless.
     *
     * @return array<int, PromptArgument>
     */
    public function getArguments(): array;

    /**
     * True when the prompt nudges the LLM to call write tools. Filtered out of
     * `prompts/list` for tokens / installs that can't write — keeps the menu
     * clean of options that would just error on selection.
     */
    public function getRequiresWrite(): bool;

    /**
     * Literal message body, pre-substitution. The renderer replaces
     * `{{argument_name}}` placeholders with the request's argument values.
     *
     * @return array<int, PromptMessage>
     */
    public function getMessages(): array;
}
