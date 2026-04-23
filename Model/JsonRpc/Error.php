<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

/**
 * JSON-RPC 2.0 error object.
 */
final class Error
{
    /**
     * @param array<string, mixed>|null $data Optional context (e.g. schema validation errors).
     */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly ?array $data = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $arr['data'] = $this->data;
        }
        return $arr;
    }
}
