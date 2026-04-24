<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Auth;

use Magento\Framework\App\DeploymentConfig;
use RuntimeException;

/**
 * Deterministic HMAC-SHA256 hasher for MCP bearer tokens.
 *
 * **Why HMAC not password_hash():** bearer tokens are already 256 bits of
 * entropy — brute-force isn't the threat. The threat is a leaked DB dump
 * letting attackers impersonate tokens. HMAC keyed by the install's crypt key
 * means the DB rows alone aren't enough to forge a valid bearer; you need the
 * key file too.
 *
 * **Why deterministic:** same plaintext → same hash, so we can index the
 * `token_hash` column and do O(1) lookup at authentication time. Timing-safe
 * comparison happens via {@see self::verify()}.
 */
class TokenHasher
{
    private const ALGO = 'sha256';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Derive the HMAC-SHA256 hash for a plaintext bearer.
     *
     * @param string $plaintext
     * @return string
     */
    public function hash(string $plaintext): string
    {
        return hash_hmac(self::ALGO, $plaintext, $this->key());
    }

    /**
     * Timing-safe comparison against a stored hash.
     *
     * @param string $plaintext
     * @param string $storedHash
     * @return bool
     */
    public function verify(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($plaintext));
    }

    /**
     * Fetch the per-install crypt key used to seed the HMAC.
     *
     * @return string
     */
    private function key(): string
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Install crypt key missing from app/etc/env.php — cannot hash MCP tokens.');
        }
        return $key;
    }
}
