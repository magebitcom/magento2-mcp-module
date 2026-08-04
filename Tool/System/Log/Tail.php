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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.log.tail` — the newest lines of one log file, read from the
 * end so the file size does not matter.
 */
class Tail implements ToolInterface
{
    public const TOOL_NAME = 'system.log.tail';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_log_tail';

    private const DEFAULT_LINES = 200;
    private const MIN_LINES = 1;
    private const MAX_LINES = 2000;

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
        return 'Tail Log File';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the most recent lines of one log file — the fastest way to '
            . 'see what went wrong right after reproducing a problem. Get the '
            . 'file name from `system.log.list` first; only files in the log '
            . 'directory can be read. Reads from the end of the file only, so it '
            . 'is safe on very large logs. Lines come back oldest-first, exactly '
            . 'as written, newest at the bottom. Use `system.log.grep` instead '
            . 'when you already know the string to look for — tailing a busy log '
            . 'to find one old event will not work, because only the tail is '
            . 'read. `reached_start_of_file: false` means the file has earlier '
            . 'content that was not read; `output_truncated: true` means the '
            . 'response hit its byte budget and older requested lines were '
            . 'dropped; `long_lines_truncated` counts lines shortened because a '
            . 'single line exceeded the per-line cap. CAUTION: log lines and '
            . 'stack traces often contain customer data, tokens, or credentials '
            . '— quote only the part that explains the problem, and never repeat '
            . 'anything that looks like a secret back to the operator.';
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
            ->integer('lines', fn (IntegerBuilder $i) => $i
                ->minimum(self::MIN_LINES)
                ->maximum(self::MAX_LINES)
                ->description('How many trailing lines to return. Default 200.')
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
        $lines = $this->clampInt($arguments['lines'] ?? null, self::DEFAULT_LINES, self::MIN_LINES, self::MAX_LINES);

        $read = $this->logReader->tail($path, $lines);

        $json = json_encode([
            'file' => $name,
            'requested_lines' => $lines,
            'returned_lines' => $read['returned_lines'],
            'file_size_bytes' => $read['file_size_bytes'],
            'bytes_read' => $read['bytes_read'],
            'reached_start_of_file' => $read['reached_start_of_file'],
            'output_truncated' => $read['output_truncated'] || $read['byte_limit_reached'],
            'long_lines_truncated' => $read['truncated_lines'],
            'caps' => [
                'max_lines' => self::MAX_LINES,
                'max_line_length' => LogReader::MAX_LINE_LENGTH,
                'max_output_bytes' => LogReader::MAX_OUTPUT_BYTES,
                'max_bytes_read' => LogReader::MAX_TAIL_BYTES,
            ],
            'lines' => $read['lines'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the log excerpt as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            // Log content is never summarized into the audit row — only the file
            // name and how much was read.
            auditSummary: [
                'file' => $name,
                'requested_lines' => $lines,
                'returned_lines' => $read['returned_lines'],
            ]
        );
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
