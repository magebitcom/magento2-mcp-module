<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data\OAuth;

/**
 * Immutable bootstrap preset for the OAuth client edit form's "Preset"
 * dropdown. Selecting an entry pre-fills Name + Redirect URIs so admins
 * don't need to look up the right callback URL for known clients
 * (e.g. Claude Web).
 */
interface ClientPresetInterface
{
    /**
     * Stable identifier — also the option `value` rendered in the dropdown.
     * Must match `^[a-z][a-z0-9_]*$` so it can travel safely as a JS key.
     */
    public function getId(): string;

    /**
     * Human-readable label shown in the dropdown.
     */
    public function getLabel(): string;

    /**
     * Default Name to write into the form's Name input. May be empty for
     * the always-first "Custom" entry.
     */
    public function getName(): string;

    /**
     * Redirect URIs to write into the textarea (one per line).
     *
     * @return array<int, string>
     */
    public function getRedirectUris(): array;
}
