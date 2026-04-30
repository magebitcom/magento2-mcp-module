<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use RuntimeException;

/**
 * Public-facing `GET /mcp/oauth/authorize` — the URL advertised in the
 * RFC 8414 authorization-server-metadata document.
 *
 * Coverage:
 *   1. Unknown client_id → inline 400 (we MUST NOT redirect to an unverified URI per OAuth 2.1 §4.1.2.1).
 *   2. Mismatched redirect_uri → inline 400 (same rule).
 *   3. Missing PKCE code_challenge → 302 back to redirect_uri with `error=invalid_request&state=<echo>`.
 *   4. Valid request → 302 to the admin-area authorize URL with `?h=<nonce>`. The
 *      frontend deliberately does not render consent or read the admin session;
 *      it stashes the validated params and hands off to the adminhtml controller
 *      where the admin session cookie is reachable. The admin URL is never in
 *      any public document — only in the user's browser address bar during
 *      flow completion.
 *
 * The interactive Approve/Deny path lives at `/<adminFrontName>/magebit_mcp/oauth/authorize`
 * and is exercised by adminhtml-area integration coverage (out of scope for this
 * api-functional suite, which can't easily simulate an authenticated admin
 * navigation through Magento's full admin auth plugin chain).
 *
 * Like {@see MetadataTest}, we must temporarily disable
 * `web/url/redirect_to_base` because the test container reaches nginx via the
 * internal `nginx.mcp-module.docker:8080` alias rather than the configured
 * canonical base URL host — without the toggle Magento bounces every GET to
 * the canonical URL before the controller runs.
 */
class AuthorizeTest extends McpTestCase
{
    protected ?bool $issueToken = false;

    private ?string $previousRedirectToBase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->resourceConnection()->getConnection();
        $table = $connection->getTableName('core_config_data');
        $row = $connection->fetchRow(
            $connection->select()->from($table, 'value')
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
                ->where('path = ?', 'web/url/redirect_to_base')
        );
        $this->previousRedirectToBase = is_array($row) && isset($row['value']) ? (string) $row['value'] : null;
        $connection->insertOnDuplicate(
            $table,
            ['scope' => 'default', 'scope_id' => 0, 'path' => 'web/url/redirect_to_base', 'value' => '0']
        );
        $this->flushConfigCache();
    }

    protected function tearDown(): void
    {
        $connection = $this->resourceConnection()->getConnection();
        $table = $connection->getTableName('core_config_data');
        if ($this->previousRedirectToBase === null) {
            $connection->delete($table, [
                'scope = ?' => 'default',
                'scope_id = ?' => 0,
                'path = ?' => 'web/url/redirect_to_base',
            ]);
        } else {
            $connection->insertOnDuplicate(
                $table,
                [
                    'scope' => 'default',
                    'scope_id' => 0,
                    'path' => 'web/url/redirect_to_base',
                    'value' => $this->previousRedirectToBase,
                ]
            );
        }
        $this->flushConfigCache();
        parent::tearDown();
    }

    public function testAuthorizeRedirectsValidRequestToAdminHandoff(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        try {
            $params = http_build_query([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://example.com/cb',
                'state' => 'abc',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
            ]);
            $response = $this->getHtml($this->baseUrl() . '/mcp/oauth/authorize?' . $params);

            // Frontend authorize never renders consent. It validates, stashes,
            // and 302s to the admin URL with a single-use `h=<nonce>`. The
            // admin URL is never in a public document — only here, in the
            // user's browser, where they're already an admin operator.
            self::assertSame(302, $response['status']);
            $location = $response['headers']['location'] ?? '';
            self::assertStringContainsString('/magebit_mcp/oauth/authorize', $location);
            // Magento's BackendUrl renders extra params as path segments
            // (`…/authorize/h/<nonce>/`) rather than as a query string. Match
            // either form so the test doesn't lock to one URL flavor.
            self::assertMatchesRegularExpression('#(?:[?&]h=|/h/)[A-Za-z0-9]{32,}#', $location);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    public function testAuthorizeRejectsUnknownClientId(): void
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => '00000000-0000-4000-8000-000000000000',
            'redirect_uri' => 'https://example.com/cb',
            'state' => 'abc',
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
        ]);
        $response = $this->getHtml($this->baseUrl() . '/mcp/oauth/authorize?' . $params);

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('invalid_client', $response['body']);
    }

    public function testAuthorizeRejectsRedirectUriMismatchInline(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        try {
            $params = http_build_query([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://attacker.com/steal',
                'state' => 'abc',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
            ]);
            $response = $this->getHtml($this->baseUrl() . '/mcp/oauth/authorize?' . $params);

            // Per OAuth 2.1 §4.1.2.1: redirect_uri mismatch MUST NOT redirect.
            self::assertSame(400, $response['status']);
            self::assertStringContainsString('Invalid redirect URI', $response['body']);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    public function testAuthorizeMissingPkceChallengeRedirectsWithError(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        try {
            $params = http_build_query([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://example.com/cb',
                'state' => 'abc',
                // Note: no code_challenge.
            ]);
            $response = $this->getHtml($this->baseUrl() . '/mcp/oauth/authorize?' . $params);

            self::assertSame(302, $response['status']);
            $location = $response['headers']['location'] ?? '';
            self::assertStringStartsWith('https://example.com/cb?error=invalid_request', $location);
            self::assertStringContainsString('state=abc', $location);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    private function baseUrl(): string
    {
        if (!defined('TESTS_BASE_URL')) {
            throw new RuntimeException('TESTS_BASE_URL is not defined; check phpunit_rest.xml(.dist).');
        }
        /** @var string $base */
        $base = TESTS_BASE_URL;
        return rtrim($base, '/');
    }

    /**
     * Issue a plain GET against the authorize endpoint and capture both the
     * response body and the lowercase-keyed response headers (so callers can
     * read e.g. `headers['location']` without case worries).
     *
     * @phpstan-return array{status: int, body: string, headers: array<string, string>}
     */
    private function getHtml(string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html',
                'X-Forwarded-Proto: https',
                'X-Forwarded-For: 127.0.0.1',
            ],
        ]);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('cURL request failed: ' . $error);
        }
        $rawString = (string) $raw;
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $headerBlock = substr($rawString, 0, $headerSize);
        $body = substr($rawString, $headerSize);

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $this->parseHeaders($headerBlock),
        ];
    }

    /**
     * @phpstan-return array<string, string>
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        foreach (preg_split("/\r?\n/", trim($block)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    private function objectManager(): ObjectManagerInterface
    {
        /** @var ObjectManagerInterface $om */
        $om = Bootstrap::getObjectManager();
        return $om;
    }

    private function resourceConnection(): ResourceConnection
    {
        /** @var ResourceConnection $rc */
        $rc = $this->objectManager()->get(ResourceConnection::class);
        return $rc;
    }

    private function flushConfigCache(): void
    {
        /** @var TypeListInterface $cache */
        $cache = $this->objectManager()->get(TypeListInterface::class);
        $cache->cleanType('config');
    }
}
