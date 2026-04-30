<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use stdClass;

class Response
{
    /**
     * @param int|string|null $id
     * @param array<string, mixed>|null $result
     * @param Error|null $error
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly ?array $result = null,
        public readonly ?Error $error = null
    ) {
    }

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $result
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function success(int|string|null $id, array $result): self
    {
        return new self($id, $result, null);
    }

    /**
     * @param int|string|null $id
     * @param ErrorCode $code
     * @param string $message
     * @param array<string, mixed>|null $data
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function failure(
        int|string|null $id,
        ErrorCode $code,
        string $message,
        ?array $data = null
    ): self {
        return new self($id, null, new Error($code, $message, $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'jsonrpc' => '2.0',
            'id' => $this->id,
        ];
        if ($this->error !== null) {
            $out['error'] = $this->error->toArray();
        } else {
            $out['result'] = ($this->result === null || $this->result === []) ? new stdClass() : $this->result;
        }
        return $out;
    }
}
