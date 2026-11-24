<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * Decides whether a session may receive `structuredContent` and `outputSchema`.
 * MCP added both fields in protocol 2025-06-18; older or unnegotiated sessions must not see them.
 */
class StructuredOutputPolicy
{
    private const LEGACY_VERSION = '2025-03-26';

    /**
     * @param string|null $protocolHeader Value of the Mcp-Protocol-Version request header
     * @return bool
     */
    public function isSupported(?string $protocolHeader): bool
    {
        if ($protocolHeader === null) {
            return false;
        }
        // Duplicate headers may fold into a comma list (ProtocolVersionValidator accepts them);
        // any legacy entry marks the session as legacy.
        $seen = false;
        foreach (explode(',', $protocolHeader) as $candidate) {
            $version = trim($candidate);
            if ($version === '') {
                continue;
            }
            if ($version === self::LEGACY_VERSION) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }
}
