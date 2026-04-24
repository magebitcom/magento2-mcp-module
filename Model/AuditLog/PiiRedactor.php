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
 * Replaces PII fields in tool arguments with a deterministic HMAC "fingerprint"
 * before they're written to the audit log.
 *
 * **Why not just drop them:** an auditor legitimately needs to know *"was this
 * customer looked up three times by three different tokens today?"*. Storing the
 * raw email would let a DB attacker enumerate customers; storing a length-16
 * HMAC keyed by `crypt/key` still lets the auditor group "same input =
 * same hash" without storing the original value.
 *
 * **Keys matched:** case-insensitive, any field whose name contains one of
 * {@see self::DEFAULT_SENSITIVE}. Additional keys can be supplied via di.xml.
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
     * @param array $additionalSensitiveKeys Extra case-insensitive fragments.
     * @phpstan-param array<int, string> $additionalSensitiveKeys
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
     * Walk a mixed value and replace PII-keyed entries with their HMAC fingerprint.
     *
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
     * True if the field name contains any of the configured sensitive fragments.
     *
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
     * Produce a short HMAC-SHA256 fingerprint for a redacted value.
     *
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
     * Fetch the per-install crypt key used to salt fingerprints.
     *
     * @return string
     */
    private function key(): string
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || $key === '') {
            // A globally-known fallback key is equivalent to no key — would
            // let any installation with a broken env.php enumerate PII across
            // every other installation. Fail loudly; AuditLogger's outer
            // try/catch converts this into a logged warning and a dropped
            // row, which is the correct failure mode.
            throw new RuntimeException(
                'Install crypt key missing from app/etc/env.php — cannot fingerprint PII for audit.'
            );
        }
        return $key;
    }
}
