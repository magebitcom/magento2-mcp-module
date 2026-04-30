<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for MCP api-functional tests.
 *
 * Wraps a thin cURL-based HTTP client around the MCP endpoint at
 * `${TESTS_BASE_URL}mcp` and exposes JSON-RPC helpers. Mirrors what
 * {@see \Magento\TestFramework\TestCase\WebapiAbstract} does for REST/SOAP,
 * but stays out of that base class because its routing layer (REST resource
 * paths, SOAP service contracts) is irrelevant for MCP and would inject
 * headers we want to control explicitly.
 *
 * Subclasses set protected properties before calling parent::setUp():
 *   - $allowWrites — passed straight to {@see TokenFixture::issueForAdmin()}.
 *   - $issueToken  — when false, no token is minted (auth-failure tests).
 */
abstract class McpTestCase extends TestCase
{
    protected const DEFAULT_ORIGIN = 'https://localhost';
    protected const PROTOCOL_VERSION = '2025-06-18';

    /**
     * NOTE on nullability — Magento's
     * {@see \Magento\TestFramework\Workaround\Cleanup\TestCaseProperties::endTestSuite()}
     * iterates every property on every test instance and calls
     * `$property->setValue($test, null)`. Non-nullable typed properties
     * reject that and abort the test run. Every property below is therefore
     * declared nullable, with the actual default applied in {@see setUp()}.
     */
    protected ?bool $allowWrites = null;

    protected ?bool $issueToken = null;

    protected ?string $bearerToken = null;

    protected ?int $tokenRowId = null;

    protected ?string $origin = null;

    protected ?string $endpoint = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('TESTS_BASE_URL')) {
            throw new RuntimeException('TESTS_BASE_URL is not defined; check phpunit_rest.xml(.dist).');
        }
        $this->endpoint = rtrim((string) TESTS_BASE_URL, '/') . '/mcp';
        $this->allowWrites ??= false;
        $this->issueToken ??= true;
        $this->origin ??= self::DEFAULT_ORIGIN;

        if ($this->issueToken) {
            $issued = TokenFixture::issueForAdmin('adminUser', $this->allowWrites);
            $this->bearerToken = $issued['token'];
            $this->tokenRowId = $issued['id'];
        }
    }

    protected function tearDown(): void
    {
        if ($this->tokenRowId !== null) {
            TokenFixture::delete($this->tokenRowId);
            $this->tokenRowId = null;
            $this->bearerToken = null;
        }
        parent::tearDown();
    }

    /**
     * Send `initialize`. Per MCP spec, this method is exempt from the
     * `Mcp-Protocol-Version` header requirement.
     *
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    protected function initialize(): array
    {
        return $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'magebit-mcp-test', 'version' => '0.0.0'],
            ],
        ], ['skipProtocolVersionHeader' => true]);
    }

    /**
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    protected function ping(): array
    {
        return $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
        ]);
    }

    /**
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    protected function toolsList(): array
    {
        return $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);
    }

    /**
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    protected function toolsCall(string $name, array $arguments = []): array
    {
        return $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => empty($arguments) ? new \stdClass() : $arguments,
            ],
        ]);
    }

    /**
     * Send a raw JSON-RPC envelope. Returns the decoded HTTP response.
     *
     * Options:
     *   - `skipProtocolVersionHeader` (bool) — omit `Mcp-Protocol-Version`.
     *   - `skipAuthorizationHeader`   (bool) — omit `Authorization`.
     *   - `headers` (array<string, string>) — override / add headers.
     *   - `body`    (string)                — override the request body verbatim
     *                                         (skips JSON encoding of $payload).
     *
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-param array{
     *     skipProtocolVersionHeader?: bool,
     *     skipAuthorizationHeader?: bool,
     *     headers?: array<string, string>,
     *     body?: string,
     * } $options
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    protected function request(array $payload, array $options = []): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Origin' => $this->origin,
            'Mcp-Protocol-Version' => self::PROTOCOL_VERSION,
            // The request reaches nginx over plaintext on the internal docker
            // network, but Magento's `web/secure/base_url` is `https://…`, so a
            // secure-only controller (which `/mcp` effectively is once
            // `web/secure/use_in_frontend=1`) would 302 → https. Mirror what
            // Traefik adds in production so the controller treats this as a
            // legitimate TLS-terminated request.
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '127.0.0.1',
        ];

        if (($options['skipProtocolVersionHeader'] ?? false) === true) {
            unset($headers['Mcp-Protocol-Version']);
        }

        if ($this->bearerToken !== null && ($options['skipAuthorizationHeader'] ?? false) !== true) {
            $headers['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        foreach ($options['headers'] ?? [] as $name => $value) {
            if ($value === '') {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }

        $body = $options['body'] ?? json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to JSON-encode request payload.');
        }

        return $this->httpPost($this->endpoint, $body, $headers);
    }

    /**
     * @phpstan-param array<string, string> $headers
     * @phpstan-return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

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
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
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
            'headers' => $this->parseHeaders($headerBlock),
            'body' => $this->decodeJsonBody($bodyText),
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

    /**
     * @phpstan-return array<string, mixed>|null
     */
    private function decodeJsonBody(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     */
    protected function assertJsonRpcSuccess(array $response): void
    {
        self::assertSame(200, $response['status'], 'Expected HTTP 200 for JSON-RPC success.');
        $body = $response['body'];
        self::assertNotNull($body, 'Expected JSON body in response.');
        self::assertSame('2.0', $body['jsonrpc'] ?? null);
        self::assertArrayHasKey('result', $body, 'Expected JSON-RPC `result`, got error: '
            . json_encode($body['error'] ?? null));
        self::assertArrayNotHasKey('error', $body);
    }

    /**
     * @phpstan-param array{status: int, headers: array<string, string>, body: array<string, mixed>|null} $response
     */
    protected function assertJsonRpcError(array $response, int $expectedCode, ?int $expectedHttpStatus = null): void
    {
        if ($expectedHttpStatus !== null) {
            self::assertSame($expectedHttpStatus, $response['status']);
        }
        $body = $response['body'];
        self::assertNotNull($body, 'Expected JSON body in response.');
        self::assertSame('2.0', $body['jsonrpc'] ?? null);
        self::assertArrayHasKey('error', $body, 'Expected JSON-RPC `error`, got result: '
            . json_encode($body['result'] ?? null));
        $error = $body['error'];
        self::assertIsArray($error);
        self::assertSame($expectedCode, $error['code'] ?? null, sprintf(
            'Expected error.code %d, got %s (message: %s)',
            $expectedCode,
            (string) ($error['code'] ?? 'null'),
            (string) ($error['message'] ?? '')
        ));
    }
}
