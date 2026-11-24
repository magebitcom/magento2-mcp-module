<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

/**
 * Collects the per-item rows of one bulk run and renders the result envelope.
 */
class BatchResult
{
    /**
     * @var array<int, array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}>
     */
    private array $rows = [];

    private ?string $notice = null;

    /**
     * @param int $index
     * @param string|null $key
     * @param ItemResult $result
     * @return void
     */
    public function add(int $index, ?string $key, ItemResult $result): void
    {
        $this->rows[] = $result->toRow($index, $key);
    }

    /**
     * @return array<int, array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array{total: int, ok: int, failed: int, skipped: int}
     */
    public function summary(): array
    {
        $summary = ['total' => count($this->rows), 'ok' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->rows as $row) {
            if ($row['status'] === ItemResult::STATUS_OK) {
                $summary['ok']++;
            } elseif ($row['status'] === ItemResult::STATUS_ERROR) {
                $summary['failed']++;
            } elseif ($row['status'] === ItemResult::STATUS_SKIPPED) {
                $summary['skipped']++;
            }
        }
        return $summary;
    }

    /**
     * True only when there is at least one row and every row is an error.
     *
     * @return bool
     */
    public function allFailed(): bool
    {
        $summary = $this->summary();
        return $summary['total'] > 0 && $summary['failed'] === $summary['total'];
    }

    /**
     * @return string|null
     */
    public function getNotice(): ?string
    {
        return $this->notice;
    }

    /**
     * @param string|null $notice
     * @return void
     */
    public function setNotice(?string $notice): void
    {
        $this->notice = $notice;
    }

    /**
     * @param bool $dryRun
     * @return array{dry_run: bool, summary: array{total: int, ok: int, failed: int, skipped: int}, results: array<int, array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}>, notice?: string}
     */
    public function toEnvelope(bool $dryRun): array
    {
        $envelope = [
            'dry_run' => $dryRun,
            'summary' => $this->summary(),
            'results' => $this->rows,
        ];
        if ($this->notice !== null) {
            $envelope['notice'] = $this->notice;
        }
        return $envelope;
    }
}
