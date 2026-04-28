<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use RuntimeException;

/**
 * Public OAuth discovery documents.
 *
 * Both endpoints are unauthenticated by design (RFC 9728 / RFC 8414) — clients
 * must be able to fetch them to discover where to authenticate. We therefore
 * skip token issuance via {@see McpTestCase::$issueToken} = false.
 *
 * Test-environment caveat: this suite reaches the controllers via the
 * `nginx.magento-demo.docker:8080` internal alias, which doesn't match the
 * configured `web/secure/base_url` host — so Magento's frontend
 * `RequestPreprocessor` would 302 GETs to the canonical base URL. We disable
 * `web/url/redirect_to_base` for the duration of the test class and restore
 * it after. In production (request reaches Traefik on the canonical host),
 * the bounce never fires.
 */
class MetadataTest extends McpTestCase
{
    protected ?bool $issueToken = false;
    private ?string $previousRedirectToBase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
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
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
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

    private function flushConfigCache(): void
    {
        Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\Cache\TypeListInterface::class)
            ->cleanType('config');
    }

    public function testProtectedResourceMetadataExposesAuthorizationServer(): void
    {
        $url = rtrim((string) TESTS_BASE_URL, '/') . '/mcp/oauth/protectedresourcemetadata';
        $response = $this->getJson($url);

        self::assertSame(200, $response['status'], 'Expected HTTP 200 from protected-resource-metadata.');
        $payload = $response['payload'];
        self::assertArrayHasKey('resource', $payload);
        self::assertStringEndsWith('/mcp', (string) $payload['resource']);
        self::assertIsArray($payload['authorization_servers']);
        self::assertNotEmpty($payload['authorization_servers']);
        self::assertSame(['header'], $payload['bearer_methods_supported']);
        self::assertSame(['mcp'], $payload['scopes_supported']);
    }

    public function testAuthorizationServerMetadataAdvertisesPkceAndGrants(): void
    {
        $url = rtrim((string) TESTS_BASE_URL, '/') . '/mcp/oauth/authorizationservermetadata';
        $response = $this->getJson($url);

        self::assertSame(200, $response['status'], 'Expected HTTP 200 from authorization-server-metadata.');
        $payload = $response['payload'];
        self::assertArrayHasKey('issuer', $payload);
        self::assertSame(['code'], $payload['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $payload['grant_types_supported']);
        self::assertSame(['S256'], $payload['code_challenge_methods_supported']);
        self::assertContains('client_secret_basic', $payload['token_endpoint_auth_methods_supported']);
        self::assertContains('client_secret_post', $payload['token_endpoint_auth_methods_supported']);
        self::assertStringEndsWith('/mcp/oauth/authorize', (string) $payload['authorization_endpoint']);
        self::assertStringEndsWith('/mcp/oauth/token', (string) $payload['token_endpoint']);
    }

    /**
     * Issue a plain GET against the metadata endpoint. We don't need
     * {@see McpTestCase::request()} here because these endpoints are not
     * JSON-RPC and don't require any of the MCP-specific headers (Origin,
     * Mcp-Protocol-Version, Authorization). The `X-Forwarded-Proto: https`
     * header is still required so Magento doesn't 302 to HTTPS before the
     * controller gets to run.
     *
     * @phpstan-return array{status: int, payload: array<string, mixed>}
     */
    private function getJson(string $url): array
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
                'Accept: application/json',
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

        $headers = substr($rawString, 0, $headerSize);
        $body = substr($rawString, $headerSize);
        if ($status !== 200) {
            fwrite(STDERR, "DEBUG headers:\n" . $headers . "\n");
        }
        $decoded = $body === '' ? null : json_decode($body, true);
        return [
            'status' => $status,
            'payload' => is_array($decoded) ? $decoded : [],
        ];
    }
}
