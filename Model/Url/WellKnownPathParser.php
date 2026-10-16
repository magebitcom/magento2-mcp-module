<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Url;

/**
 * Reads the OAuth discovery URL shapes this module serves. The router, the
 * redirect exemption and the metadata controllers all go through here.
 */
class WellKnownPathParser
{
    private const WELL_KNOWN = '.well-known/';

    private const MCP = 'mcp';

    /**
     * Returns null when the path is not a discovery URL this module serves.
     *
     * @param string $pathInfo
     * @return WellKnownPath|null
     */
    public function parse(string $pathInfo): ?WellKnownPath
    {
        $path = trim($pathInfo, '/');

        foreach ([WellKnownPath::AUTHORIZATION_SERVER, WellKnownPath::PROTECTED_RESOURCE] as $document) {
            $segment = self::WELL_KNOWN . $document;
            if ($path === $segment) {
                return new WellKnownPath($document, '', false);
            }
            if (str_starts_with($path, $segment . '/')) {
                return new WellKnownPath($document, trim(substr($path, strlen($segment)), '/'), false);
            }
        }

        // Some clients put `.well-known` after the issuer path. Only the
        // authorization-server document supports that, and only under /mcp.
        $segment = '/' . self::WELL_KNOWN . WellKnownPath::AUTHORIZATION_SERVER;
        if (str_ends_with($path, $segment)) {
            $prefix = trim(substr($path, 0, -strlen($segment)), '/');
            if ($prefix === self::MCP || str_ends_with($prefix, '/' . self::MCP)) {
                return new WellKnownPath(WellKnownPath::AUTHORIZATION_SERVER, $prefix, true);
            }
        }

        return null;
    }
}
