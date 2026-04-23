<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

use Magebit\Mcp\Model\Config\ModuleConfig;
use RuntimeException;

/**
 * DNS-rebinding defense required by the MCP Streamable HTTP transport spec.
 *
 * The allowlist is sourced from store config
 * (`magebit_mcp/security/allowed_origins`) — one origin per line, exact
 * match or a trailing `*` wildcard that is anchored to a host-component
 * boundary: after the prefix, the only allowed characters are end-of-string,
 * `:` (port), or `/` (path). This rejects hosts crafted to match the prefix
 * via subdomains, e.g. `http://localhost.attacker.com` does NOT match
 * `http://localhost*`.
 *
 * Non-browser clients (curl, Claude Desktop, ChatGPT Desktop) omit the Origin
 * header entirely — we accept a missing or empty value. We DO NOT accept the
 * literal string `null`: that is what sandboxed iframes and `data:` URIs send,
 * i.e. exactly the attacker shapes this validator exists to block.
 *
 * Bearer authentication is still the primary access control; this check is
 * defense-in-depth against DNS-rebinding attacks against browser contexts.
 */
class OriginValidator
{
    public function __construct(
        private readonly ModuleConfig $config
    ) {
    }

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
            // Empty list combined with the "missing Origin → allow" rule would
            // silently admit every browser request. Fail loud instead of
            // discovering the misconfiguration after a breach. The store-config
            // default in etc/config.xml seeds the loopback entries.
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

    private function matches(string $origin, string $pattern): bool
    {
        if (!str_ends_with($pattern, '*')) {
            return $origin === $pattern;
        }

        $prefix = substr($pattern, 0, -1);
        if (!str_starts_with($origin, $prefix)) {
            return false;
        }

        // Next character after the prefix must be a host-component boundary so
        // that `http://localhost*` matches `http://localhost:3000` and
        // `http://localhost/path` but NOT `http://localhost.attacker.com`.
        $rest = substr($origin, strlen($prefix));
        return $rest === '' || $rest[0] === ':' || $rest[0] === '/';
    }
}
