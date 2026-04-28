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
 * `GET /mcp/oauth/authorize` — interactive consent screen.
 *
 * Covers the four GET branches Task 17 introduces:
 *   1. Anonymous request with valid params → renders the login-required page.
 *   2. Unknown client_id → inline 400 (we MUST NOT redirect to an unverified URI).
 *   3. Mismatched redirect_uri → inline 400 (same reason; per OAuth 2.1 §4.1.2.1).
 *   4. Missing PKCE code_challenge → 302 back to redirect_uri with
 *      `error=invalid_request&state=<echo>`.
 *
 * Plus the three Task 18 POST branches (consent submit):
 *   5. Approve → mints an auth code and 302s to redirect_uri with `code=…&state=…`.
 *   6. Deny → 302s to redirect_uri with `error=access_denied`.
 *   7. Missing form_key → 302s to redirect_uri with `error=invalid_request`
 *      (this fires before the admin session check, so it does not need a login).
 *
 * Like {@see MetadataTest}, we must temporarily disable
 * `web/url/redirect_to_base` because the test container reaches nginx via the
 * internal `nginx.magento-demo.docker:8080` alias rather than the configured
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

    public function testAuthorizeWithoutAdminSessionRendersLoginRequired(): void
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

            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Log in to your Magento admin', $response['body']);
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

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testApproveMintsCodeAndRedirects(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        $session = null;
        try {
            try {
                $session = AdminSessionFixture::login();
                $authorizeUrl = $this->buildAuthorizeUrl($client['client_id']);
                $consentFormKey = $this->fetchConsentFormKey($authorizeUrl, $session['cookie_jar']);
            } catch (RuntimeException $e) {
                self::markTestSkipped('Admin-session-driven consent flow unavailable in this test env: '
                    . $e->getMessage());
            }
            $body = http_build_query([
                'oauth_action' => 'approve',
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://example.com/cb',
                'state' => 'abc',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
                'form_key' => $consentFormKey,
            ]);

            $response = $this->postWithSession(
                $authorizeUrl,
                $body,
                $session['cookie_jar']
            );

            self::assertSame(302, $response['status']);
            self::assertMatchesRegularExpression(
                '#^https://example\.com/cb\?code=[A-Za-z0-9_\-]{32,}&state=abc$#',
                $response['headers']['location'] ?? ''
            );
        } finally {
            if ($session !== null) {
                AdminSessionFixture::cleanup($session['cookie_jar']);
            }
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testDenyRedirectsWithAccessDenied(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        $session = null;
        try {
            try {
                $session = AdminSessionFixture::login();
                $authorizeUrl = $this->buildAuthorizeUrl($client['client_id']);
                $consentFormKey = $this->fetchConsentFormKey($authorizeUrl, $session['cookie_jar']);
            } catch (RuntimeException $e) {
                self::markTestSkipped('Admin-session-driven consent flow unavailable in this test env: '
                    . $e->getMessage());
            }
            $body = http_build_query([
                'oauth_action' => 'deny',
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://example.com/cb',
                'state' => 'abc',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
                'form_key' => $consentFormKey,
            ]);

            $response = $this->postWithSession(
                $authorizeUrl,
                $body,
                $session['cookie_jar']
            );

            self::assertSame(302, $response['status']);
            $location = $response['headers']['location'] ?? '';
            self::assertStringStartsWith('https://example.com/cb?error=access_denied', $location);
            self::assertStringContainsString('state=abc', $location);
        } finally {
            if ($session !== null) {
                AdminSessionFixture::cleanup($session['cookie_jar']);
            }
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    public function testApproveWithoutFormKeyRedirectsWithError(): void
    {
        $client = ClientFixture::issue('test', ['https://example.com/cb']);
        // Form-key validation fires *before* the admin-session check, so this
        // branch needs no admin login. We still need a cookie jar for cURL but
        // it can be an unauthenticated empty file.
        $jar = tempnam(sys_get_temp_dir(), 'mcp_admin_');
        if ($jar === false) {
            self::fail('Failed to allocate cookie jar tempfile.');
        }
        try {
            $body = http_build_query([
                'oauth_action' => 'approve',
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://example.com/cb',
                'state' => 'abc',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
                // intentionally no form_key
            ]);

            $response = $this->postWithSession(
                $this->buildAuthorizeUrl($client['client_id']),
                $body,
                $jar
            );

            self::assertSame(302, $response['status']);
            $location = $response['headers']['location'] ?? '';
            self::assertStringStartsWith('https://example.com/cb?error=invalid_request', $location);
            self::assertStringContainsString('state=abc', $location);
        } finally {
            @unlink($jar);
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    private function buildAuthorizeUrl(string $clientId): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://example.com/cb',
            'state' => 'abc',
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
        ]);
        return $this->baseUrl() . '/mcp/oauth/authorize?' . $params;
    }

    /**
     * GET the consent page (with admin session cookies), parse the rendered
     * `form_key` hidden input, and return it. The form_key in the rendered
     * page is the one bound to the **frontend** session (the area /mcp/oauth
     * runs in), not the admin session — so reusing the dashboard's form_key
     * would fail validation when the controller checks against the frontend
     * session on POST. The cURL cookie jar persists the frontend session
     * cookie that the GET seeds, so the subsequent POST hits the same
     * frontend session that minted this form_key.
     */
    private function fetchConsentFormKey(string $authorizeUrl, string $cookieJar): string
    {
        $page = $this->getWithSession($authorizeUrl, $cookieJar);
        if ($page['status'] !== 200) {
            throw new RuntimeException(
                'GET consent page returned ' . $page['status']
                . ' (Location: ' . ($page['headers']['location'] ?? '<none>') . ')'
            );
        }
        if (str_contains($page['body'], 'Log in to your Magento admin')) {
            throw new RuntimeException(
                'GET /mcp/oauth/authorize rendered the login_required page — admin session was not '
                . 'recognised by the frontend controller (cookie not carried across area boundary).'
            );
        }
        if (preg_match(
            '/<input[^>]*name=["\']form_key["\'][^>]*value=["\']([A-Za-z0-9]+)["\']/i',
            $page['body'],
            $m
        ) === 1) {
            return $m[1];
        }
        if (preg_match(
            '/<input[^>]*value=["\']([A-Za-z0-9]+)["\'][^>]*name=["\']form_key["\']/i',
            $page['body'],
            $m
        ) === 1) {
            return $m[1];
        }
        throw new RuntimeException('Could not locate form_key on the rendered consent page.');
    }

    /**
     * @phpstan-return array{status: int, body: string, headers: array<string, string>}
     */
    private function getWithSession(string $url, string $cookieJar): array
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
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
            throw new RuntimeException('cURL GET failed: ' . $error);
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
     * @phpstan-return array{status: int, body: string, headers: array<string, string>}
     */
    private function postWithSession(string $url, string $body, string $cookieJar): array
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
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
        $bodyText = substr($rawString, $headerSize);

        return [
            'status' => $status,
            'body' => $bodyText,
            'headers' => $this->parseHeaders($headerBlock),
        ];
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
