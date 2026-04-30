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
 * Shared base class for `/mcp/oauth/token` api-functional tests.
 *
 * Extracts the cURL helpers (form-encoded POST + plain GET), header parsing,
 * admin-user lookup, and base-URL/object-manager accessors used by every
 * grant-type test against the token endpoint. Concrete subclasses
 * ({@see TokenAuthCodeTest}, {@see TokenRefreshTest}) only own the
 * grant-specific orchestration.
 *
 * Token issuance is opt-out (`$issueToken = false`) — these tests mint their
 * own access tokens via the OAuth flow rather than the CLI shortcut.
 */
abstract class TokenEndpointTestCase extends McpTestCase
{
    protected ?bool $issueToken = false;

    /**
     * Issue a form-encoded POST against `/mcp/oauth/token` and capture the
     * status, decoded JSON body, and lowercase-keyed response headers.
     *
     * @phpstan-param array<string, string> $body
     * @phpstan-return array{status: int, payload: array<string, mixed>, headers: array<string, string>}
     */
    protected function postToken(array $body): array
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
     * Plain GET against the token endpoint — used to assert the controller
     * rejects non-POST methods with `405 + Allow: POST`.
     *
     * @phpstan-return array{status: int, headers: array<string, string>}
     */
    protected function getToken(): array
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
    protected function parseHeaders(string $block): array
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

    protected function loadAdminUserId(string $username): int
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

    protected function baseUrl(): string
    {
        if (!defined('TESTS_BASE_URL')) {
            throw new RuntimeException('TESTS_BASE_URL is not defined; check phpunit_rest.xml(.dist).');
        }
        /** @var string $base */
        $base = TESTS_BASE_URL;
        return rtrim($base, '/');
    }

    protected function objectManager(): ObjectManagerInterface
    {
        /** @var ObjectManagerInterface $om */
        $om = Bootstrap::getObjectManager();
        return $om;
    }
}
