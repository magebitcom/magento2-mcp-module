<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

use Magebit\Mcp\Model\Config\ModuleConfig;
use RuntimeException;

/**
 * DNS-rebinding defense required by the MCP Streamable HTTP transport spec.
 *
 * Allowlist (`magebit_mcp/security/allowed_origins`) — one origin per line,
 * exact match or trailing `*` anchored to a host-component boundary: after
 * the prefix only end-of-string, `:` (port), or `/` (path) are allowed. This
 * rejects `http://localhost.attacker.com` against `http://localhost*`.
 *
 * Missing/empty Origin is accepted (curl, Claude Desktop, ChatGPT Desktop
 * omit it). The literal string `null` is REJECTED — that's what sandboxed
 * iframes and `data:` URIs send, exactly the shape this validator exists for.
 *
 * Defense-in-depth; bearer auth remains the primary access control.
 */
class OriginValidator
{
    /**
     * @param ModuleConfig $config
     */
    public function __construct(
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * @param string|null $origin
     * @return bool
     */
    public function isAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        if ($origin === 'null') {
            return false;
        }

        $allowlist = $this->config->getAllowedOrigins();
        if ($allowlist === []) {
            // Empty list + "missing Origin → allow" would silently admit every
            // browser request. Fail loud; etc/config.xml seeds loopback defaults.
            throw new RuntimeException(
                'MCP allowed-origins list is empty — set magebit_mcp/security/allowed_origins.'
            );
        }

        foreach ($allowlist as $pattern) {
            if ($this->matches($origin, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $origin
     * @param string $pattern
     * @return bool
     */
    private function matches(string $origin, string $pattern): bool
    {
        if (!str_ends_with($pattern, '*')) {
            return $origin === $pattern;
        }

        $prefix = substr($pattern, 0, -1);
        if (!str_starts_with($origin, $prefix)) {
            return false;
        }

        // Enforce host-component boundary so `http://localhost*` does NOT
        // match `http://localhost.attacker.com`.
        $rest = substr($origin, strlen($prefix));
        return $rest === '' || $rest[0] === ':' || $rest[0] === '/';
    }
}
