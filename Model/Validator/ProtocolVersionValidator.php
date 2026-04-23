<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
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
 */
class ProtocolVersionValidator
{
    public const LATEST = '2025-06-18';

    /** @var array<int, string> */
    private const SUPPORTED = [
        '2025-06-18',
        '2025-03-26',
    ];

    public function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    public function getLatest(): string
    {
        return self::LATEST;
    }
}
