<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api;

/**
 * Opt-in hook for a tool that depends on an optional feature (a module that may be missing or disabled).
 *
 * While isAvailable() returns false the tool is treated as not installed: `tools/list` hides it and
 * `tools/call` answers -32010 TOOL_NOT_FOUND, exactly like an unregistered tool, for every role. Checked
 * before the token scope and ACL gates, so the refusal never blames the admin role.
 */
interface AvailabilityAwareInterface
{
    /**
     * @return bool
     */
    public function isAvailable(): bool;
}
