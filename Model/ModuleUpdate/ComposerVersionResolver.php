<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\ModuleUpdate;

use Composer\InstalledVersions;

/**
 * Thin seam over the static Composer runtime API so callers stay unit-testable.
 *
 * Returns null when the package is not Composer-installed (e.g. modules mounted
 * directly into app/code), which the caller treats as "version unknown, skip".
 */
class ComposerVersionResolver
{
    /**
     * @param string $packageName
     * @return ?string
     */
    public function getInstalledVersion(string $packageName): ?string
    {
        if (!InstalledVersions::isInstalled($packageName)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion($packageName);
        return is_string($version) && $version !== '' ? $version : null;
    }
}
