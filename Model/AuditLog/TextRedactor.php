<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\AuditLog;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\PiiRedactor\AnalyzerOptions;
use Magebit\PiiRedactor\PiiRedactor as Library;
use Magebit\PiiRedactor\RedactionConfig;
use Throwable;

/**
 * Pattern-based redaction for free-form text the key-name redactor cannot see into — a SQL string,
 * an exception message. Fails closed: unredactable text is dropped, never written through.
 */
class TextRedactor
{
    public const FAILURE_PLACEHOLDER = '[REDACTION_FAILED]';

    private ?Library $library = null;

    /**
     * @param FingerprintStrategy $strategy
     * @param LoggerInterface $logger
     * @param array<array-key, string> $entityTypes
     * @param int $maxLength
     */
    public function __construct(
        private readonly FingerprintStrategy $strategy,
        private readonly LoggerInterface $logger,
        private readonly array $entityTypes = [],
        private readonly int $maxLength = 1000000
    ) {
    }

    /**
     * @param string $text
     * @return string
     */
    public function redact(string $text): string
    {
        if ($text === '' || $this->entityTypes === []) {
            return $text;
        }

        try {
            return $this->library()->redact($text)->text();
        } catch (Throwable $exception) {
            $this->logger->warning(
                'PII redaction failed; the value was dropped from the audit trail.',
                ['exception' => $exception->getMessage()]
            );

            return self::FAILURE_PLACEHOLDER;
        }
    }

    /**
     * Built once and held: the library's own guidance is to reuse an instance, since wiring its
     * detectors costs about as much as a short redaction.
     *
     * @return Library
     */
    private function library(): Library
    {
        if ($this->library === null) {
            $this->library = new Library(
                null,
                new RedactionConfig([], $this->strategy),
                new AnalyzerOptions(
                    entityTypes: array_values($this->entityTypes),
                    maxLength: $this->maxLength
                )
            );
        }

        return $this->library;
    }
}
