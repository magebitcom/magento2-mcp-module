<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
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
final class Response
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly ?array $result = null,
        public readonly ?Error $error = null
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function success(int|string|null $id, array $result): self
    {
        return new self($id, $result, null);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function failure(int|string|null $id, int $code, string $message, ?array $data = null): self
    {
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
            // Empty assoc arrays encode as `[]` in JSON; JSON-RPC requires `{}`.
            $out['result'] = ($this->result === null || $this->result === []) ? new stdClass() : $this->result;
        }
        return $out;
    }
}
