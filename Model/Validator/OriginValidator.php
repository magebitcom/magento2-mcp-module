<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

use InvalidArgumentException;

/**
 * DNS-rebinding defense required by the MCP Streamable HTTP transport spec.
 *
 * The allowlist is configured via etc/di.xml — default entries cover the
 * loopback origins every bundled MCP client uses. Patterns accept either an
 * exact match or a trailing `*` wildcard that is anchored to a host-component
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
    /** @var array<int, string> */
    private array $allowedOrigins;

    /**
     * @param array<int, string> $allowedOrigins Exact match or trailing-`*` wildcard patterns.
     */
    public function __construct(array $allowedOrigins = [])
    {
        $list = array_values($allowedOrigins);
        if ($list === []) {
            // Empty list combined with the "missing Origin → allow" rule would
            // silently admit every request. Fail loud at boot time instead of
            // discovering the misconfiguration after a breach.
            throw new InvalidArgumentException(
                'OriginValidator allowlist is empty — check etc/di.xml for a broken argument merge.'
            );
        }
        $this->allowedOrigins = $list;
    }

    public function isAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        if ($origin === 'null') {
            return false;
        }

        foreach ($this->allowedOrigins as $pattern) {
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
