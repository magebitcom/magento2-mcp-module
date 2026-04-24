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
 * Accepts any value in {@see self::SUPPORTED}; unsupported → 400 at the controller.
 * HTTP allows duplicate same-value headers to fold into a comma list (MCP Inspector's
 * proxy does this), so we split on comma and accept if ANY entry is supported.
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
     * @return string
     */
    public function getLatest(): string
    {
        return self::LATEST;
    }
}
