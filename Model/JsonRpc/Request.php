<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\JsonRpc;

use InvalidArgumentException;

/**
 * Parsed JSON-RPC 2.0 request envelope. A notification is a request whose `id`
 * field is entirely absent (NOT `id: null` — `id: null` is a valid request);
 * {@see $isNotification} tracks this so the server can suppress responses for
 * notifications even on error.
 */
class Request
{
    /**
     * @param int|string|null $id
     * @param bool $isNotification
     * @param string $method
     * @param array $params
     * @phpstan-param array<string, mixed> $params
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly bool $isNotification,
        public readonly string $method,
        public readonly array $params
    ) {
    }

    /**
     * Parse a decoded JSON body into a request envelope.
     *
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function fromArray(array $data): self
    {
        $jsonrpc = $data['jsonrpc'] ?? null;
        if ($jsonrpc !== '2.0') {
            throw new InvalidArgumentException('Missing or invalid "jsonrpc" field — must equal "2.0".');
        }

        $method = $data['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw new InvalidArgumentException('Missing or invalid "method" field.');
        }

        $paramsRaw = $data['params'] ?? [];
        if (!is_array($paramsRaw)) {
            throw new InvalidArgumentException('"params" must be an object or array when present.');
        }
        /** @var array<string, mixed> $params */
        $params = $paramsRaw;

        $isNotification = !array_key_exists('id', $data);
        $id = null;
        if (!$isNotification) {
            $rawId = $data['id'];
            if ($rawId !== null && !is_int($rawId) && !is_string($rawId)) {
                throw new InvalidArgumentException('"id" must be a string, integer, or null.');
            }
            $id = $rawId;
        }

        return new self($id, $isNotification, $method, $params);
    }
}
