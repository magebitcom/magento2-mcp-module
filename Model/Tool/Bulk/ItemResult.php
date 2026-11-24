<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Tool\Bulk;

/**
 * Outcome of processing one item of a bulk call.
 *
 * @phpstan-type Row array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}
 */
final class ItemResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param string $status
     * @param string|null $errorCode
     * @param string|null $message
     * @param int|string|null $id
     */
    private function __construct(
        private readonly string $status,
        private readonly ?string $errorCode,
        private readonly ?string $message,
        private readonly int|string|null $id
    ) {
    }

    /**
     * @param int|string|null $id
     * @param string|null $message
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function ok(int|string|null $id = null, ?string $message = null): self
    {
        return new self(self::STATUS_OK, null, $message, $id);
    }

    /**
     * @param string $errorCode One of {@see BulkErrorCode}.
     * @param string $message
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function error(string $errorCode, string $message): self
    {
        return new self(self::STATUS_ERROR, $errorCode, $message, null);
    }

    /**
     * @param string $errorCode One of {@see BulkErrorCode}, for example CANCELLED.
     * @param string|null $message
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function skipped(string $errorCode, ?string $message = null): self
    {
        return new self(self::STATUS_SKIPPED, $errorCode, $message, null);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return int|string|null
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * @param int $index
     * @param string|null $key
     * @return array{index: int, key: string|null, status: string, error_code: string|null, message: string|null, id: int|string|null}
     */
    public function toRow(int $index, ?string $key): array
    {
        return [
            'index' => $index,
            'key' => $key,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'id' => $this->id,
        ];
    }
}
