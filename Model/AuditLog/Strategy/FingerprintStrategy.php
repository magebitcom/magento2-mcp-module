<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog\Strategy;

use Magebit\PiiRedactor\PiiMatch;
use Magebit\PiiRedactor\Strategy\StrategyInterface;
use Magento\Framework\App\DeploymentConfig;
use RuntimeException;

/**
 * Replaces a detected value with a keyed fingerprint rather than a constant token, so repeated
 * lookups stay groupable in the audit trail without the value itself being stored.
 */
class FingerprintStrategy implements StrategyInterface
{
    private const FINGERPRINT_LENGTH = 16;

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * @param PiiMatch $match
     * @param string $original
     * @return string
     * @throws RuntimeException
     */
    public function redact(PiiMatch $match, string $original): string
    {
        $hash = hash_hmac('sha256', $original, $this->key());

        return sprintf('[%s:%s]', $match->entityType, substr($hash, 0, self::FINGERPRINT_LENGTH));
    }

    /**
     * @return string
     * @throws RuntimeException
     */
    private function key(): string
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('crypt/key is unavailable, so audit fingerprints cannot be computed.');
        }

        return $key;
    }
}
