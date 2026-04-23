<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Validator;

/**
 * DNS-rebinding defense required by the MCP Streamable HTTP transport spec.
 *
 * The allowlist is configured via etc/di.xml (default: http(s)://localhost*,
 * http(s)://127.0.0.1*). Missing, empty, or literal "null" Origin headers are
 * accepted — non-browser clients (curl, Claude Desktop, ChatGPT Desktop) omit
 * the header entirely.
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
        $this->allowedOrigins = array_values($allowedOrigins);
    }

    public function isAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '' || $origin === 'null') {
            return true;
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
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($origin, substr($pattern, 0, -1));
        }
        return $origin === $pattern;
    }
}
