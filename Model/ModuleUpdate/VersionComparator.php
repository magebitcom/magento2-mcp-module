<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ModuleUpdate;

use Composer\Semver\VersionParser;
use Throwable;

/**
 * Orders two version strings after normalizing both.
 *
 * Composer's own Comparator falls back to raw version_compare, which mis-orders a `v`-prefixed
 * string against a bare one (`v1.2.3` from Packagist vs `1.2.3` installed). Normalizing both sidesteps that.
 */
class VersionComparator
{
    /**
     * @param VersionParser $versionParser
     */
    public function __construct(
        private readonly VersionParser $versionParser
    ) {
    }

    /**
     * Whether $candidate represents a strictly newer release than $current.
     *
     * @param string $candidate
     * @param string $current
     * @return bool
     */
    public function isNewer(string $candidate, string $current): bool
    {
        try {
            $normalizedCandidate = $this->versionParser->normalize($candidate);
            $normalizedCurrent = $this->versionParser->normalize($current);
        } catch (Throwable) {
            return false;
        }

        return version_compare($normalizedCandidate, $normalizedCurrent, '>');
    }
}
