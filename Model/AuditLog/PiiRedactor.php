<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog;

use Magento\Framework\App\DeploymentConfig;
use RuntimeException;

/**
 * Replaces PII fields with a length-16 HMAC-SHA256 fingerprint keyed by
 * `crypt/key`. Same input → same fingerprint, so auditors can group repeated
 * lookups without the DB storing raw PII (an attacker with the dump alone
 * can't enumerate — they need the key file too).
 *
 * Keys matched: case-insensitive substring of {@see self::DEFAULT_SENSITIVE}
 * plus any additions supplied via di.xml.
 */
class PiiRedactor
{
    /** @var array<int, string> */
    private const DEFAULT_SENSITIVE = [
        'email',
        'telephone',
        'phone',
        'password',
        'street',
        'postcode',
        'card',
        'token',
        'authorization',
        'cookie',
        'ssn',
        'tax_id',
    ];

    private const FINGERPRINT_LENGTH = 16;

    /** @var array<int, string> */
    private array $sensitiveKeys;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param array<int, string> $additionalSensitiveKeys
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        array $additionalSensitiveKeys = []
    ) {
        $this->sensitiveKeys = array_values(array_unique(array_map(
            'strtolower',
            array_merge(self::DEFAULT_SENSITIVE, array_values($additionalSensitiveKeys))
        )));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitive($key)) {
                    $out[$key] = $this->fingerprint($item);
                    continue;
                }
                $out[$key] = $this->redact($item);
            }
            return $out;
        }
        return $value;
    }

    /**
     * @param string $key
     * @return bool
     */
    private function isSensitive(string $key): bool
    {
        $needle = strtolower($key);
        foreach ($this->sensitiveKeys as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function fingerprint(mixed $value): string
    {
        $stringified = is_scalar($value) ? (string) $value : json_encode($value);
        if ($stringified === false || $stringified === '') {
            return '***';
        }
        $digest = hash_hmac('sha256', $stringified, $this->key());
        return '***[' . substr($digest, 0, self::FINGERPRINT_LENGTH) . ']';
    }

    /**
     * @return string
     */
    private function key(): string
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || $key === '') {
            // Never fall back to a known constant — that would let any install with
            // a broken env.php enumerate PII across every other install. Fail loud;
            // AuditLogger's outer try/catch logs a warning and drops the row.
            throw new RuntimeException(
                'Install crypt key missing from app/etc/env.php — cannot fingerprint PII for audit.'
            );
        }
        return $key;
    }
}
