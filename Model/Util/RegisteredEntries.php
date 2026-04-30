<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

use InvalidArgumentException;

/**
 * Validates a `<name → entry>` map registered via DI: every entry must implement
 * the expected interface, every name must match the canonical dotted regex, and
 * every map key must equal `entry->getName()`. Used by {@see \Magebit\Mcp\Model\Tool\ToolRegistry}
 * and {@see \Magebit\Mcp\Model\Prompt\PromptRegistry} so the same compile-time
 * drift check fires for both surfaces.
 */
class RegisteredEntries
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * @param array<int|string, mixed> $entries
     * @param array<int, class-string> $requiredInterfaces
     * @param string $entryLabel
     * @return void
     * @throws InvalidArgumentException
     */
    public static function assertValid(array $entries, array $requiredInterfaces, string $entryLabel): void
    {
        foreach ($entries as $key => $entry) {
            if (!is_object($entry)) {
                throw new InvalidArgumentException(sprintf(
                    '%s "%s" must be an object, got %s.',
                    $entryLabel,
                    (string) $key,
                    get_debug_type($entry)
                ));
            }
            foreach ($requiredInterfaces as $iface) {
                if (!$entry instanceof $iface) {
                    throw new InvalidArgumentException(sprintf(
                        '%s "%s" must implement %s, got %s.',
                        $entryLabel,
                        (string) $key,
                        $iface,
                        get_debug_type($entry)
                    ));
                }
            }
            $name = method_exists($entry, 'getName') ? (string) $entry->getName() : '';
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    '%s name "%s" is invalid — must match %s.',
                    $entryLabel,
                    $name,
                    self::NAME_PATTERN
                ));
            }
            if ($key !== $name) {
                throw new InvalidArgumentException(sprintf(
                    '%s registration mismatch: di.xml key "%s" does not match getName() "%s".',
                    $entryLabel,
                    (string) $key,
                    $name
                ));
            }
        }
    }
}
