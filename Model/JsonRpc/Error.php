<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * JSON-RPC 2.0 error object.
 */
class Error
{
    /**
     * @param ErrorCode $code
     * @param string $message
     * @param array|null $data Optional context (e.g. schema validation errors).
     * @phpstan-param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly ErrorCode $code,
        public readonly string $message,
        public readonly ?array $data = null
    ) {
    }

    /**
     * Render as the wire payload under the JSON-RPC `error` field.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'code' => $this->code->value,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $arr['data'] = $this->data;
        }
        return $arr;
    }
}
