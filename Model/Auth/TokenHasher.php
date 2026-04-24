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
 * HMAC (not password_hash): tokens already carry 256 bits of entropy; the
 * threat is a leaked DB dump, so keying by the install's crypt key means rows
 * alone can't forge a bearer — the key file is also required.
 *
 * Deterministic so `token_hash` can be UNIQUE-indexed for O(1) lookup.
 * Use {@see self::verify()} for timing-safe comparison.
 */
class TokenHasher
{
    private const ALGO = 'sha256';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
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
