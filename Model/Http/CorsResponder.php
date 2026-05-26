<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Http;

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Emits CORS headers on the OAuth metadata and token endpoints so MCP clients
 * (browser-hosted inspectors, embeds) can call them cross-origin. Wildcard
 * origin is safe here — these endpoints are intentionally public and the token
 * endpoint authenticates via client_id/client_secret, not cookies.
 */
class CorsResponder
{
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, Accept';

    private const MAX_AGE_SECONDS = 600;

    /**
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
        $this->appendVaryOrigin($response);
    }

    /**
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

    /**
     * @param HttpResponse $response
     * @return void
     */
    private function appendVaryOrigin(HttpResponse $response): void
    {
        $existing = $response->getHeader('Vary');
        $current = $existing instanceof HeaderInterface ? trim($existing->getFieldValue()) : '';
        if ($current === '') {
            $response->setHeader('Vary', 'Origin', true);
            return;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $current))));
        foreach ($parts as $part) {
            if (strcasecmp($part, 'Origin') === 0 || $part === '*') {
                return;
            }
        }
        $parts[] = 'Origin';
        $response->setHeader('Vary', implode(', ', $parts), true);
    }
}
