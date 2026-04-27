<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Exact-match redirect-URI check per OAuth 2.1 §4.1.3 — no prefix matching, no
 * scheme normalization. The AS is allowed to preserve the request's query and
 * fragment when redirecting back, so we strip them before comparing the base
 * against the client's allowlist entry.
 */
class RedirectUriValidator
{
    /**
     * @param Client $client
     * @param string $providedUri
     * @return bool
     */
    public function isAllowed(Client $client, string $providedUri): bool
    {
        $providedBase = $this->stripQueryAndFragment($providedUri);
        foreach ($client->getRedirectUris() as $allowed) {
            if (hash_equals($allowed, $providedBase)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $uri
     * @return string
     */
    private function stripQueryAndFragment(string $uri): string
    {
        // Cut at first '#' OR '?', whichever appears first.
        $cut = strlen($uri);
        $hashPos = strpos($uri, '#');
        if ($hashPos !== false && $hashPos < $cut) {
            $cut = $hashPos;
        }
        $qPos = strpos($uri, '?');
        if ($qPos !== false && $qPos < $cut) {
            $cut = $qPos;
        }
        return substr($uri, 0, $cut);
    }
}
