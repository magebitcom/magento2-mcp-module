<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Exception\OAuthException;

/**
 * Translates OAuth `scope` strings to {@see Scope} enum values. Stateless. Per RFC 6749
 * §3.3, missing/empty input defaults to `mcp:read`. Per-tool grants live in
 * {@see ToolGrantResolver}; this validator only handles the protocol-level vocabulary.
 */
class ScopeValidator
{
    /**
     * @param string|null $raw
     * @return array<int, Scope>
     * @throws OAuthException
     */
    public function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [Scope::READ];
        }

        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $scopes = [];
        $seen = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $scope = Scope::tryFrom($token);
            if ($scope === null) {
                throw new OAuthException(
                    'invalid_scope',
                    sprintf('Unknown scope: %s', $token)
                );
            }
            if (isset($seen[$scope->value])) {
                continue;
            }
            $seen[$scope->value] = true;
            $scopes[] = $scope;
        }

        return $scopes === [] ? [Scope::READ] : $scopes;
    }

    /**
     * @param array<int, Scope> $scopes
     * @return string
     */
    public function canonicalize(array $scopes): string
    {
        return implode(' ', array_map(static fn (Scope $s): string => $s->value, $scopes));
    }
}
