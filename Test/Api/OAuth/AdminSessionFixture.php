<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\OAuth;

use RuntimeException;

/**
 * Performs an HTTP login dance against the Magento admin and returns a
 * persisted cookie-jar path plus a fresh form key — the two ingredients an
 * api-functional test needs to POST to an admin-session-protected URL such as
 * `/mcp/oauth/authorize` after consent.
 *
 * The flow:
 *   1. GET `/admin` to harvest the login form's `form_key` and seed the
 *      cookie jar with the pre-auth session.
 *   2. POST `login[username]` / `login[password]` / `form_key` to
 *      `/admin/admin/auth/login`. Magento answers with a 302 to the dashboard
 *      and rotates the session cookie; cURL persists both into the jar.
 *   3. GET the dashboard so we have a *post-login* form key suitable for
 *      subsequent admin POSTs.
 *
 * Both Magento test admin credentials and the password in
 * `Magento\TestFramework\Bootstrap::ADMIN_PASSWORD` are well-known — this
 * fixture deliberately reuses them so the `Magento/User/_files/user_with_role`
 * data fixture (which seeds an `adminUser` with that exact password) can drive
 * the login.
 */
final class AdminSessionFixture
{
    public const ADMIN_USERNAME = 'adminUser';
    /**
     * Mirrors `Magento\TestFramework\Bootstrap::ADMIN_PASSWORD`. The
     * `user_with_role.php` fixture sets the admin password to this value.
     */
    public const ADMIN_PASSWORD = 'password1';

    /**
     * Log in as `adminUser` and return the cookie-jar path + a fresh form key.
     *
     * The cookie jar is a temp file written by cURL in Netscape format. It is
     * the caller's responsibility to call {@see self::cleanup()} when done.
     *
     * @phpstan-return array{cookie_jar: string, form_key: string}
     */
    public static function login(): array
    {
        $base = self::baseUrl();
        $jar = tempnam(sys_get_temp_dir(), 'mcp_admin_');
        if ($jar === false) {
            throw new RuntimeException('Failed to allocate cookie jar tempfile.');
        }

        // Step 1: GET admin login page, capture pre-auth form_key + cookies.
        $loginPageHtml = self::curlGet($base . '/admin', $jar);
        $formKey = self::extractFormKey($loginPageHtml);
        if ($formKey === '') {
            @unlink($jar);
            throw new RuntimeException('Failed to extract form_key from admin login page.');
        }

        // Step 2: POST credentials. Magento responds with a 302 to the dashboard
        // and rotates the session cookie into the jar.
        $postFields = http_build_query([
            'login[username]' => self::ADMIN_USERNAME,
            'login[password]' => self::ADMIN_PASSWORD,
            'form_key' => $formKey,
        ]);
        self::curlPost($base . '/admin/admin/auth/login', $postFields, $jar);

        // Step 3: GET the dashboard to harvest a post-login form_key the caller
        // can use for subsequent admin-area POSTs.
        $dashboardHtml = self::curlGet($base . '/admin/admin/dashboard', $jar);
        $newFormKey = self::extractFormKey($dashboardHtml);
        if ($newFormKey === '') {
            @unlink($jar);
            throw new RuntimeException(
                'Failed to extract post-login form_key — admin login likely failed '
                . '(check that the user_with_role.php data fixture ran).'
            );
        }

        return ['cookie_jar' => $jar, 'form_key' => $newFormKey];
    }

    /**
     * Delete the cookie-jar tempfile produced by {@see self::login()}.
     */
    public static function cleanup(string $cookieJar): void
    {
        if ($cookieJar !== '' && file_exists($cookieJar)) {
            @unlink($cookieJar);
        }
    }

    private static function curlGet(string $url, string $cookieJar): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
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
        curl_close($curl);
        return (string) $raw;
    }

    private static function curlPost(string $url, string $body, string $cookieJar): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
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
            throw new RuntimeException('cURL POST failed: ' . $error);
        }
        curl_close($curl);
        return (string) $raw;
    }

    private static function extractFormKey(string $html): string
    {
        // Magento's form key input is rendered as `<input name="form_key" ... value="...">`.
        // Match either attribute order to be robust against template tweaks.
        if (preg_match(
            '/<input[^>]*name=["\']form_key["\'][^>]*value=["\']([A-Za-z0-9]+)["\']/i',
            $html,
            $m
        ) === 1) {
            return $m[1];
        }
        if (preg_match(
            '/<input[^>]*value=["\']([A-Za-z0-9]+)["\'][^>]*name=["\']form_key["\']/i',
            $html,
            $m
        ) === 1) {
            return $m[1];
        }
        return '';
    }

    private static function baseUrl(): string
    {
        if (!defined('TESTS_BASE_URL')) {
            throw new RuntimeException('TESTS_BASE_URL is not defined; check phpunit_rest.xml.');
        }
        /** @var string $base */
        $base = TESTS_BASE_URL;
        return rtrim($base, '/');
    }
}
