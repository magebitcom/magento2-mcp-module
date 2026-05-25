<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Http;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Emits CORS headers for the OAuth metadata and token endpoints so MCP clients
 * (e.g. the MCP Inspector running in a browser tab at localhost:6274) can
 * fetch them cross-origin. The OAuth 2.0 / RFC 8414 / RFC 9728 documents are
 * intentionally public and unauthenticated — wildcard origin is the standard
 * disposition. The token endpoint is browser-callable too; auth there is by
 * `client_id` / `client_secret`, not cookies, so allowing `*` is safe.
 */
class CorsResponder
{
    /**
     * Headers MCP clients legitimately send on token-exchange or metadata fetches.
     * `Authorization` and `Content-Type` cover the token endpoint; the rest are
     * for parity with the MCP transport itself.
     */
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, Mcp-Protocol-Version, '
        . 'Mcp-Session-Id, Last-Event-ID, X-Requested-With';

    private const MAX_AGE_SECONDS = 600;

    /**
     * Add CORS headers to a real (GET/POST) response.
     *
     * @param HttpResponse $response
     * @param string $allowedMethods Comma-separated, e.g. "GET, OPTIONS" or "POST, OPTIONS".
     * @return void
     */
    public function applyHeaders(HttpResponse $response, string $allowedMethods): void
    {
        $response->setHeader('Access-Control-Allow-Origin', '*', true);
        $response->setHeader('Access-Control-Allow-Methods', $allowedMethods, true);
        $response->setHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS, true);
        $response->setHeader('Access-Control-Expose-Headers', 'WWW-Authenticate', true);
        $response->setHeader('Access-Control-Max-Age', (string) self::MAX_AGE_SECONDS, true);
        // Any caching proxy must vary on Origin so a cached `*` response isn't reused for a
        // request that would have warranted a narrower allowlist response in the future.
        $response->setHeader('Vary', 'Origin', false);
    }

    /**
     * Emit a 204 preflight response. Browsers don't read the body of an OPTIONS
     * response — only the headers — so an empty body is correct.
     *
     * @param HttpResponse $response
     * @param string $allowedMethods Comma-separated method list.
     * @return HttpResponse
     */
    public function emitPreflight(HttpResponse $response, string $allowedMethods): HttpResponse
    {
        $this->applyHeaders($response, $allowedMethods);
        $response->setHeader('Content-Length', '0', true);
        $response->setHttpResponseCode(204);
        $response->setBody('');
        return $response;
    }
}
