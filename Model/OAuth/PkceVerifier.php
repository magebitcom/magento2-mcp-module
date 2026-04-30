<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

/**
 * RFC 7636 PKCE proof-of-possession check. Only `S256` is supported.
 */
class PkceVerifier
{
    public const METHOD_S256 = 'S256';

    /**
     * @param string $codeVerifier
     * @param string $codeChallenge
     * @param string $method
     * @return bool
     */
    public function verify(string $codeVerifier, string $codeChallenge, string $method): bool
    {
        if ($method !== self::METHOD_S256) {
            return false;
        }
        if (!$this->isValidVerifierFormat($codeVerifier)) {
            return false;
        }
        $computed = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        return hash_equals($codeChallenge, $computed);
    }

    /**
     * @param string $verifier
     * @return bool
     */
    private function isValidVerifierFormat(string $verifier): bool
    {
        // RFC 7636 §4.1: 43-128 chars, [A-Za-z0-9-._~].
        $len = strlen($verifier);
        if ($len < 43 || $len > 128) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._~-]+$/', $verifier) === 1;
    }

    /**
     * @param string $bytes
     * @return string
     */
    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
