<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Exception\OAuthException;
use Magento\Framework\App\Response\HttpInterface as HttpResponse;

/**
 * Shapes RFC 6749 §5.2 token-endpoint error bodies. The OAuth `/token`
 * controller hands a caught {@see OAuthException} to {@see emit()}; the helper
 * sets the status code, the JSON body, the no-cache headers, and — for
 * `invalid_client` — the `WWW-Authenticate: Basic` challenge.
 */
class OAuthErrorResponse
{
    /**
     * Emits an RFC 6749 §5.2 token-endpoint error body on the supplied response.
     *
     * @param HttpResponse $response
     * @param OAuthException $error
     * @return HttpResponse
     */
    public function emit(HttpResponse $response, OAuthException $error): HttpResponse
    {
        $payload = [
            'error' => $error->oauthError,
            'error_description' => $error->getMessage(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{"error":"server_error"}';
        }
        $response->setHttpResponseCode($error->httpStatus);
        $response->setHeader('Content-Type', 'application/json', true);
        $response->setHeader('Cache-Control', 'no-store', true);
        $response->setHeader('Pragma', 'no-cache', true);
        if ($error->oauthError === 'invalid_client') {
            $response->setHeader('WWW-Authenticate', 'Basic realm="Magento MCP"', true);
        }
        $response->setBody($body);
        return $response;
    }
}
