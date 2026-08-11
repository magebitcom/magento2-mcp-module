<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Util;

/**
 * The one definition of a canonical config path, plus the safe way to quote an untrusted one back in
 * an error message. Shared so the allowlist and the writer can never disagree on what "canonical" is.
 */
class ConfigPathFormat
{
    /**
     * Two or more segments; the writer requires three, but that minimum is its own business.
     */
    private const PATH_PATTERN = '#^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+)+$#D';

    private const MESSAGE_MAX_LENGTH = 120;

    /**
     * Non-canonical input is refused rather than normalised, so a caller cannot slip past a prefix
     * or allowlist check with a leading space, a doubled slash or a non-breaking space.
     *
     * @param string $path
     * @return bool
     */
    public function isCanonical(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1;
    }

    /**
     * Rejection messages travel to the JSON-RPC error string and the audit row, and the value in
     * them may be wholly unvalidated bytes — so bound it and make it printable.
     *
     * @param string $value
     * @return string
     */
    public function forMessage(string $value): string
    {
        $safe = preg_replace('/[[:cntrl:]]+/', ' ', $value);
        if (!is_string($safe)) {
            return '';
        }

        // Invalid UTF-8 would otherwise fail json_encode() when the error is serialized.
        $safe = mb_convert_encoding($safe, 'UTF-8', 'UTF-8');

        return mb_strlen($safe) > self::MESSAGE_MAX_LENGTH
            ? mb_substr($safe, 0, self::MESSAGE_MAX_LENGTH) . '...'
            : $safe;
    }
}
