<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Shared "OAuth error" inline HTML page. Used when the redirect_uri is unknown
 * or invalid, so we have nowhere safe to bounce back to. UTF-8 + HTML5 escaping
 * pinned explicitly so future Symfony / PHP defaults can't loosen behaviour.
 */
class InlineErrorRenderer
{
    /**
     * @param HttpResponse $response
     * @param int $httpStatus
     * @param string $error
     * @param string $description
     * @return HttpResponse
     */
    public function render(HttpResponse $response, int $httpStatus, string $error, string $description): HttpResponse
    {
        $response->setHttpResponseCode($httpStatus);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $response->setBody(sprintf(
            '<!doctype html><html><body><h1>OAuth error</h1>'
            . '<p><strong>%s</strong>: %s</p></body></html>',
            self::escape($error),
            self::escape($description)
        ));
        return $response;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
