<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use RuntimeException;

/**
 * `POST /mcp/oauth/token` — `authorization_code` grant.
 *
 * Covers the seven branches of Task 19:
 *   1. Happy path → 200 + JSON token response, then the issued access token
 *      can be used to call `/mcp` `tools/list`.
 *   2. PKCE mismatch → 400 `invalid_grant`.
 *   3. Reusing a used code → 400 `invalid_grant`.
 *   4. redirect_uri mismatch → 400 `invalid_grant`.
 *   5. Wrong client secret → 401 `invalid_client` + `WWW-Authenticate: Basic`.
 *   6. Unsupported grant type → 400 `unsupported_grant_type`.
 *   7. GET request → 405 with `Allow: POST`.
 *
 * The PKCE values use the canonical RFC 7636 vector (verifier + challenge).
 *
 * Token issuance is opt-out (`$issueToken = false`) — these tests mint their
 * own access tokens via the OAuth flow rather than the CLI shortcut.
 */
class TokenAuthCodeTest extends McpTestCase
{
    private const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected ?bool $issueToken = false;

    /**
     * @magentoApiDataFixture Magento/User/_files/user_with_role.php
     */
    public function testAuthCodeGrantHappyPath(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);

            self::assertSame(200, $response['status']);
            $payload = $response['payload'];
            self::assertSame('Bearer', $payload['token_type'] ?? null);
            self::assertIsString($payload['access_token'] ?? null);
            self::assertIsString($payload['refresh_token'] ?? null);
            self::assertSame(3600, $payload['expires_in'] ?? null);

            $accessToken = (string) $payload['access_token'];

            // Use the access token to call /mcp tools/list — confirms the issued token works.
            $list = $this->request(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]
            );
            self::assertSame(200, $list['status']);
            $body = $list['body'];
            self::assertNotNull($body, 'Expected JSON-RPC body from tools/list.');
            self::assertArrayHasKey('result', $body);
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
    public function testAuthCodeGrantPkceMismatchReturnsInvalidGrant(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            // A different but format-valid verifier (43+ chars, allowed alphabet).
            $wrongVerifier = str_repeat('A', 43);

            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => $wrongVerifier,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);

            self::assertSame(400, $response['status']);
            self::assertSame('invalid_grant', $response['payload']['error'] ?? null);
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
    public function testAuthCodeGrantSecondUseOfSameCodeReturnsInvalidGrant(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            $body = [
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ];

            $first = $this->postToken($body);
            self::assertSame(200, $first['status'], 'First redemption should succeed.');

            $second = $this->postToken($body);
            self::assertSame(400, $second['status']);
            self::assertSame('invalid_grant', $second['payload']['error'] ?? null);
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
    public function testAuthCodeGrantWrongRedirectUriReturnsInvalidGrant(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb', 'https://example.com/other']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/other',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);

            self::assertSame(400, $response['status']);
            self::assertSame('invalid_grant', $response['payload']['error'] ?? null);
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
    public function testAuthCodeGrantWrongClientSecretReturnsInvalidClient(): void
    {
        $admin = $this->loadAdminUserId('adminUser');
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $authCode = AuthCodeFixture::issueFor(
                (int) $client['client']->getId(),
                $admin,
                'https://example.com/cb',
                self::RFC_CHALLENGE
            );

            $response = $this->postToken([
                'grant_type' => 'authorization_code',
                'code' => $authCode['code'],
                'code_verifier' => self::RFC_VERIFIER,
                'redirect_uri' => 'https://example.com/cb',
                'client_id' => $client['client_id'],
                'client_secret' => 'definitely-not-the-real-secret',
            ]);

            self::assertSame(401, $response['status']);
            self::assertSame('invalid_client', $response['payload']['error'] ?? null);
            $wwwAuth = $response['headers']['www-authenticate'] ?? '';
            self::assertStringStartsWith('Basic', $wwwAuth);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    public function testAuthCodeGrantUnknownGrantTypeReturnsUnsupported(): void
    {
        $client = ClientFixture::issue('Test', ['https://example.com/cb']);
        try {
            $response = $this->postToken([
                'grant_type' => 'password',
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);

            self::assertSame(400, $response['status']);
            self::assertSame('unsupported_grant_type', $response['payload']['error'] ?? null);
        } finally {
            $id = $client['client']->getId();
            if ($id !== null) {
                ClientFixture::delete((int) $id);
            }
        }
    }

    public function testTokenEndpointRejectsGetRequests(): void
    {
        $response = $this->getToken();

        self::assertSame(405, $response['status']);
        $allow = $response['headers']['allow'] ?? '';
        self::assertSame('POST', $allow);
    }

    /**
     * Issue a form-encoded POST against `/mcp/oauth/token` and capture the
     * status, decoded JSON body, and lowercase-keyed response headers.
     *
     * Mirrors the helpers in {@see MetadataTest} / {@see AuthorizeTest} but
     * sends `application/x-www-form-urlencoded` and JSON-decodes the body.
     *
     * @phpstan-param array<string, string> $body
     * @phpstan-return array{status: int, payload: array<string, mixed>, headers: array<string, string>}
     */
    private function postToken(array $body): array
    {
        $url = $this->baseUrl() . '/mcp/oauth/token';
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
            CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'X-Forwarded-Proto: https',
                'X-Forwarded-For: 127.0.0.1',
            ],
        ]);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('cURL POST failed: ' . $error);
        }
        $rawString = (string) $raw;
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $headerBlock = substr($rawString, 0, $headerSize);
        $bodyText = substr($rawString, $headerSize);

        $decoded = $bodyText === '' ? null : json_decode($bodyText, true);

        return [
            'status' => $status,
            'payload' => is_array($decoded) ? $decoded : [],
            'headers' => $this->parseHeaders($headerBlock),
        ];
    }

    /**
     * Plain GET against the token endpoint — the controller must respond with
     * 405 Method Not Allowed and an `Allow: POST` header.
     *
     * @phpstan-return array{status: int, headers: array<string, string>}
     */
    private function getToken(): array
    {
        $url = $this->baseUrl() . '/mcp/oauth/token';
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
                'Accept: application/json',
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

        return [
            'status' => $status,
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

    private function loadAdminUserId(string $username): int
    {
        $om = $this->objectManager();
        /** @var AdminUserLookup $lookup */
        $lookup = $om->get(AdminUserLookup::class);
        $admin = $lookup->getByUsername($username);
        $id = $admin->getId();
        if (!is_scalar($id)) {
            throw new RuntimeException(sprintf('Admin user "%s" has no id.', $username));
        }
        return (int) $id;
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

    private function objectManager(): ObjectManagerInterface
    {
        /** @var ObjectManagerInterface $om */
        $om = Bootstrap::getObjectManager();
        return $om;
    }
}
