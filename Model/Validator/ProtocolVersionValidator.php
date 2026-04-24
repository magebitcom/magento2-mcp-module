<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

/**
 * Validates the MCP-Protocol-Version request header.
 *
 * Per spec, the server returns its chosen protocol version from `initialize`;
 * subsequent requests SHOULD echo it back via the MCP-Protocol-Version header.
 * We accept any listed {@see self::SUPPORTED} version; unsupported versions
 * yield a 400 at the controller layer.
 *
 * HTTP allows duplicate headers with identical values to be folded into a
 * single comma-separated entry (the MCP Inspector's proxy does this). We
 * split on comma and accept the request if ANY value is supported, so dev
 * tooling behind a folding proxy isn't rejected spuriously.
 */
class ProtocolVersionValidator
{
    public const LATEST = '2025-06-18';

    /** @var array<int, string> */
    private const SUPPORTED = [
        '2025-06-18',
        '2025-03-26',
    ];

    /**
     * True when the supplied MCP-Protocol-Version header value includes a supported version.
     *
     * @param string $version
     * @return bool
     */
    public function isSupported(string $version): bool
    {
        foreach (explode(',', $version) as $candidate) {
            if (in_array(trim($candidate), self::SUPPORTED, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the newest protocol version this server advertises on `initialize`.
     *
     * @return string
     */
    public function getLatest(): string
    {
        return self::LATEST;
    }
}
