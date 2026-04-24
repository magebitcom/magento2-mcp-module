<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use stdClass;

/**
 * JSON-RPC 2.0 response envelope.
 *
 * Exactly one of {@see $result} / {@see $error} is non-null, per spec.
 * Construct via the {@see self::success()} / {@see self::failure()} named
 * constructors so this invariant is always maintained.
 */
class Response
{
    /**
     * @param int|string|null $id
     * @param array|null $result
     * @phpstan-param array<string, mixed>|null $result
     * @param Error|null $error
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly ?array $result = null,
        public readonly ?Error $error = null
    ) {
    }

    /**
     * Build a successful response carrying a result payload.
     *
     * Static named constructor on this value object — it has no behaviour
     * plugins would ever want to decorate, so the Magento2 "static discouraged"
     * sniff is silenced intentionally.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @param int|string|null $id
     * @param array $result
     * @phpstan-param array<string, mixed> $result
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function success(int|string|null $id, array $result): self
    {
        return new self($id, $result, null);
    }

    /**
     * Build an error response with optional structured data.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @param int|string|null $id
     * @param ErrorCode $code
     * @param string $message
     * @param array|null $data
     * @phpstan-param array<string, mixed>|null $data
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
     * Render the envelope as the JSON-RPC wire payload.
     *
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
            // Empty assoc arrays encode as `[]` in JSON; JSON-RPC requires `{}`.
            $out['result'] = ($this->result === null || $this->result === []) ? new stdClass() : $this->result;
        }
        return $out;
    }
}
