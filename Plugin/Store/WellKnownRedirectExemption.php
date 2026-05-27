<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Plugin\Store;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\BaseUrlChecker;

/**
 * Exempts the OAuth/MCP `.well-known` discovery paths from Magento's
 * redirect-to-base check. RFC 8414 §3 / RFC 9728 §3.1 require these documents
 * at the authority root, but on stores whose base URL carries a path (store
 * code) the base-URL check would otherwise 302 them to the store-coded path
 * before the WellKnownRouter runs. This treats the paths as already canonical;
 * it never adds or rewrites the store code.
 */
class WellKnownRedirectExemption
{
    /**
     * @var string[]
     */
    private const EXEMPT_PREFIXES = [
        '.well-known/oauth-protected-resource',
        '.well-known/oauth-authorization-server',
    ];

    /**
     * @param BaseUrlChecker $subject
     * @param bool $result
     * @param array<string, mixed> $uri
     * @param HttpRequest $request
     * @return bool
     */
    public function afterExecute(
        BaseUrlChecker $subject,
        bool $result,
        array $uri,
        HttpRequest $request
    ): bool {
        if ($result) {
            return true;
        }

        $path = trim((string) $request->getPathInfo(), '/');
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return $result;
    }
}
