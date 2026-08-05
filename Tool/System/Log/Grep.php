<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Tool\System\Log;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Log\LogFileResolver;
use Magebit\Mcp\Model\Log\LogReader;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.log.grep` — literal substring search over one log file.
 * Deliberately not a regex search: no pattern means no ReDoS surface.
 */
class Grep implements ToolInterface
{
    public const TOOL_NAME = 'system.log.grep';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_log_grep';

    private const MIN_NEEDLE_LENGTH = 2;
    private const MAX_NEEDLE_LENGTH = 255;

    private const DEFAULT_MAX_MATCHES = 100;
    private const MIN_MAX_MATCHES = 1;
    private const MAX_MAX_MATCHES = 1000;

    private const DEFAULT_CONTEXT_LINES = 0;
    private const MAX_CONTEXT_LINES = 5;

    private const MAX_FILE_NAME_LENGTH = 255;

    /**
     * @param LogFileResolver $logFileResolver
     * @param LogReader $logReader
     */
    public function __construct(
        private readonly LogFileResolver $logFileResolver,
        private readonly LogReader $logReader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Search Log File';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search one log file for a literal piece of text — use it to find '
            . 'every mention of an order number, a SKU, a customer email, an '
            . 'exception class, or an error message. Get the file name from '
            . '`system.log.list` first; only files in the log directory can be '
            . 'searched. `contains` is matched literally: no regular '
            . 'expressions, no `*` wildcards. The scan runs forward and stops as '
            . 'soon as a cap is reached, so `truncated: true` with '
            . '`stop_reason: max_matches` means more matches probably exist '
            . 'further down — make the search term more specific rather than '
            . 'raising the cap, and remember the newest entries are at the end of '
            . 'the file (use `system.log.tail` for "what just happened"). On a '
            . 'log too large to scan whole, the *newest* window is searched '
            . 'rather than the oldest bytes; `scan_window.covers_whole_file` '
            . 'tells you whether the whole file was covered, and when it is '
            . 'false the `line` numbers count from the start of that window, not '
            . 'the start of the file. Case-insensitive matching is the default '
            . 'and folds ASCII letters only. `context_lines` adds surrounding '
            . 'lines, which is usually how you find the stack trace belonging to '
            . 'an exception message. Each returned entry carries its line number '
            . 'and whether it matched or is context. CAUTION: log lines often '
            . 'contain customer data, tokens, or credentials — quote only what is '
            . 'needed to explain the problem, and never echo anything that looks '
            . 'like a secret.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('file', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(self::MAX_FILE_NAME_LENGTH)
                ->description('Log file name exactly as reported by '
                    . '`system.log.list`, e.g. `exception.log`. A plain file '
                    . 'name only — no directories or paths.')
                ->required()
            )
            ->string('contains', fn (StringBuilder $s) => $s
                ->minLength(self::MIN_NEEDLE_LENGTH)
                ->maxLength(self::MAX_NEEDLE_LENGTH)
                ->description('Literal text to look for, at least 2 characters. '
                    . 'Not a regular expression and not a wildcard pattern.')
                ->required()
            )
            ->boolean('case_sensitive', fn (BooleanBuilder $b) => $b
                ->description('Match letter case exactly. Defaults to `false`.')
            )
            ->integer('max_matches', fn (IntegerBuilder $i) => $i
                ->minimum(self::MIN_MAX_MATCHES)
                ->maximum(self::MAX_MAX_MATCHES)
                ->description('Stop after this many matching lines. Default 100.')
            )
            ->integer('context_lines', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->maximum(self::MAX_CONTEXT_LINES)
                ->description('Lines of surrounding context to include on each '
                    . 'side of a match. Default 0.')
            )
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        $name = $this->logFileResolver->normalize($arguments['file'] ?? null);
        $path = $this->logFileResolver->resolve($name);
        $needle = $this->needle($arguments['contains'] ?? null);
        $caseSensitive = ($arguments['case_sensitive'] ?? false) === true;
        $maxMatches = $this->clampInt(
            $arguments['max_matches'] ?? null,
            self::DEFAULT_MAX_MATCHES,
            self::MIN_MAX_MATCHES,
            self::MAX_MAX_MATCHES
        );
        $contextLines = $this->clampInt(
            $arguments['context_lines'] ?? null,
            self::DEFAULT_CONTEXT_LINES,
            0,
            self::MAX_CONTEXT_LINES
        );

        $read = $this->logReader->grep($path, $needle, $caseSensitive, $maxMatches, $contextLines);

        $json = json_encode([
            'file' => $name,
            'contains' => $needle,
            'case_sensitive' => $caseSensitive,
            'max_matches' => $maxMatches,
            'context_lines' => $contextLines,
            'match_count' => $read['match_count'],
            'lines_scanned' => $read['lines_scanned'],
            'bytes_scanned' => $read['bytes_scanned'],
            'file_size_bytes' => $read['file_size_bytes'],
            'scan_window' => $read['scan_window'],
            'truncated' => $read['truncated'],
            'stop_reason' => $read['stop_reason'],
            'long_lines_truncated' => $read['truncated_lines'],
            'caps' => [
                'max_matches' => self::MAX_MAX_MATCHES,
                'max_context_lines' => self::MAX_CONTEXT_LINES,
                'max_line_length' => LogReader::MAX_LINE_LENGTH,
                'max_output_bytes' => LogReader::MAX_OUTPUT_BYTES,
                'max_scan_bytes' => LogReader::MAX_SCAN_BYTES,
            ],
            'matches' => $read['lines'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the log search result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            // Neither the search term nor any matched line reaches the audit row
            // — log content routinely carries PII.
            auditSummary: [
                'file' => $name,
                'matches' => $read['match_count'],
                'lines_scanned' => $read['lines_scanned'],
                'truncated' => $read['truncated'],
            ]
        );
    }

    /**
     * @param mixed $value
     * @return string
     * @throws LocalizedException
     */
    private function needle(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LocalizedException(__('Argument "contains" must be a string.'));
        }
        if (strlen($value) < self::MIN_NEEDLE_LENGTH) {
            throw new LocalizedException(__(
                'Argument "contains" must be at least %1 characters long.',
                self::MIN_NEEDLE_LENGTH
            ));
        }
        if (strlen($value) > self::MAX_NEEDLE_LENGTH) {
            throw new LocalizedException(__(
                'Argument "contains" must be at most %1 characters long.',
                self::MAX_NEEDLE_LENGTH
            ));
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || !is_scalar($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
