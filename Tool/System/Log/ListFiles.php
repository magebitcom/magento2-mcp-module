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
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `system.log.list` — the entry point for log diagnostics: which log
 * files exist, how big they are, and when each was last written.
 */
class ListFiles implements ToolInterface
{
    public const TOOL_NAME = 'system.log.list';
    public const ACL_RESOURCE = 'Magebit_Mcp::tool_system_log_list';

    /**
     * @param LogFileResolver $logFileResolver
     */
    public function __construct(
        private readonly LogFileResolver $logFileResolver
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
        return 'List Log Files';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the store\'s log files with their size and last-modified '
            . 'time. Start here when diagnosing an error whose cause is not yet '
            . 'known, then read the chosen file with `system.log.tail` or search '
            . 'it with `system.log.grep`. Typical files: `exception.log` (PHP '
            . 'exceptions and stack traces — usually the most informative), '
            . '`system.log` (application warnings and errors), `debug.log` '
            . '(developer-mode detail), `magebit_mcp.log` (this MCP server\'s own '
            . 'activity). A name ending `.log.1`, `.log.2` is an older rotation '
            . 'of the same log, so prefer the un-numbered file for recent events. '
            . 'A large, recently written file usually means errors are still '
            . 'being produced. Sizes are bytes; `modified_at` is ISO-8601 UTC. '
            . 'Only files inside the log directory are ever listed or readable.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
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
        $files = $this->logFileResolver->listFiles();

        $json = json_encode([
            'files' => $files,
            'file_count' => count($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the log file listing as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: ['files' => count($files)]
        );
    }
}
