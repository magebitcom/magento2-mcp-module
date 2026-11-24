<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Model\AuditLog\AuditLogger;

/**
 * Collects the per-item audit detail of one bulk run ({@see BulkItemAuditInterface}) for its audit row.
 *
 * Bounded: it keeps leading entries up to a byte cap and only counts the rest, so a long job run holds no more than
 * that. The cap is twice the audit summary budget, because redaction can shrink entries before
 * {@see AuditLogger} fits the final summary into the budget.
 */
class ItemAuditCollector
{
    public const DEFAULT_MAX_BYTES = 2 * AuditLogger::SUMMARY_BUDGET_BYTES;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private bool $collecting = false;

    /**
     * @var list<array{index: int, key: string|null, status: string, detail: array<string, mixed>}>
     */
    private array $entries = [];

    private int $bytes = 0;

    private int $dropped = 0;

    private bool $full = false;

    /**
     * @param int $maxBytes
     */
    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES
    ) {
    }

    /**
     * Marks the run as one of a tool that gives item detail; until then nothing is collected or reported.
     *
     * @return void
     */
    public function begin(): void
    {
        $this->collecting = true;
    }

    /**
     * @param int $index
     * @param string|null $key
     * @param string $status
     * @param array<string, mixed> $detail
     * @return void
     */
    public function add(int $index, ?string $key, string $status, array $detail): void
    {
        if (!$this->collecting) {
            return;
        }
        $entry = ['index' => $index, 'key' => $key, 'status' => $status, 'detail' => $detail];
        $encoded = json_encode($entry, self::JSON_FLAGS);
        if ($encoded === false) {
            // Not storable as JSON (e.g. invalid UTF-8); counted so the row still says something was left out.
            $this->dropped++;
            return;
        }
        // +1 for the separating comma. Once one entry does not fit, later ones are only counted, so the kept
        // entries are always the leading ones.
        if ($this->full || $this->bytes + strlen($encoded) + 1 > $this->maxBytes) {
            $this->full = true;
            $this->dropped++;
            return;
        }
        $this->bytes += strlen($encoded) + 1;
        $this->entries[] = $entry;
    }

    /**
     * The keys to add to the audit summary: none when the run never began, otherwise `items` and, when entries were
     * left out, `items_truncated`.
     *
     * @return array{items?: list<array{index: int, key: string|null, status: string, detail: array<string, mixed>}>, items_truncated?: int}
     */
    public function toSummary(): array
    {
        if (!$this->collecting) {
            return [];
        }
        $summary = ['items' => $this->entries];
        if ($this->dropped > 0) {
            $summary['items_truncated'] = $this->dropped;
        }
        return $summary;
    }
}
