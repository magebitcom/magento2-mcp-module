<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * OAuth 2.1 scopes advertised in `scopes_supported` and accepted by the
 * authorize endpoint. The catalog is intentionally minimal — `mcp:read` for
 * read tools and `mcp:write` for write tools — so it lines up with the
 * existing per-token `allow_writes` gate. The seam is the enum: adding finer
 * grains later (e.g. `mcp:catalog:write`) is an enum extension, not a flow
 * change.
 */
enum Scope: string
{
    case READ = 'mcp:read';
    case WRITE = 'mcp:write';

    /**
     * Human-readable label rendered on the consent screen.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::READ => 'Read data (run read-only MCP tools)',
            self::WRITE => 'Write data (run write MCP tools, e.g. create / update / cancel)',
        };
    }

    /**
     * Canonical list of every scope the server supports. Used by the metadata
     * endpoints and as the upper bound of any client request.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [self::READ, self::WRITE];
    }

    /**
     * String values of every supported scope, suitable for `scopes_supported`.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::all());
    }
}
