<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * Byte-exact redirect-URI check per OAuth 2.1 §4.1.3. Comparison is verbatim;
 * {@see ClientCredentialIssuer} rejects registered URIs with `?` or `#` so an
 * incoming URI carrying unregistered decorations cannot spuriously match.
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
        foreach ($client->getRedirectUris() as $allowed) {
            if (hash_equals($allowed, $providedUri)) {
                return true;
            }
        }
        return false;
    }
}
