<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Index;

use InvalidArgumentException;
use JsonException;
use Magebit\Mcp\Model\JsonRpc\Dispatcher;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Request as RpcRequest;
use Magebit\Mcp\Model\Validator\OriginValidator;
use Magebit\Mcp\Model\Validator\ProtocolVersionValidator;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;

/**
 * Single HTTP endpoint for the Magebit MCP server.
 *
 * Reached as `POST /mcp` (frontName=mcp, default controller/action=index/index).
 * Accepts a JSON-RPC 2.0 envelope, runs the standard MCP compliance checks
 * (Origin allowlist, MCP-Protocol-Version), hands off to the {@see Dispatcher},
 * and writes the response directly on the HTTP response — bypassing Magento's
 * layout/result rendering entirely.
 *
 * Auth is intentionally not enforced in Phase 3. Phase 4 wires
 * \Magebit\Mcp\Model\Auth\TokenAuthenticator into the front of execute().
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly Dispatcher $dispatcher,
        private readonly OriginValidator $originValidator,
        private readonly ProtocolVersionValidator $protocolVersionValidator
    ) {
    }

    public function execute(): ResponseInterface
    {
        $origin = $this->header('Origin');
        if (!$this->originValidator->isAllowed($origin)) {
            return $this->jsonRpcError(403, null, ErrorCode::INVALID_ORIGIN, 'Origin not allowed.');
        }

        $body = (string) $this->request->getContent();

        try {
            $parsed = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->jsonRpcError(400, null, ErrorCode::PARSE_ERROR, 'Parse error: ' . $e->getMessage());
        }

        if (!is_array($parsed)) {
            return $this->jsonRpcError(400, null, ErrorCode::INVALID_REQUEST, 'Request body must be a JSON object.');
        }
        /** @var array<string, mixed> $parsed */

        try {
            $rpcRequest = RpcRequest::fromArray($parsed);
        } catch (InvalidArgumentException $e) {
            return $this->jsonRpcError(400, $this->extractId($parsed), ErrorCode::INVALID_REQUEST, $e->getMessage());
        }

        if ($rpcRequest->method !== 'initialize') {
            $version = $this->header('Mcp-Protocol-Version');
            if ($version !== null && !$this->protocolVersionValidator->isSupported($version)) {
                return $this->jsonRpcError(
                    400,
                    $rpcRequest->id,
                    ErrorCode::UNSUPPORTED_PROTOCOL_VERSION,
                    sprintf('Unsupported MCP-Protocol-Version: %s', $version)
                );
            }
        }

        $rpcResponse = $this->dispatcher->dispatch($rpcRequest);

        if ($rpcResponse === null) {
            $this->response->setHttpResponseCode(202);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody('');
            return $this->response;
        }

        return $this->writeJson(200, $rpcResponse->toArray());
    }

    /**
     * Read an HTTP request header by name. Returns null when absent or empty.
     */
    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->request->getServer($key);
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function extractId(array $parsed): int|string|null
    {
        $raw = $parsed['id'] ?? null;
        if ($raw === null || is_int($raw) || is_string($raw)) {
            return $raw;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(int $status, array $payload): ResponseInterface
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->response->setHttpResponseCode($status);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setBody(
            $encoded === false
                ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Response encoding error"}}'
                : $encoded
        );
        return $this->response;
    }

    private function jsonRpcError(int $httpStatus, int|string|null $id, int $code, string $message): ResponseInterface
    {
        return $this->writeJson($httpStatus, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }

    /**
     * Bearer-token authenticated POST endpoint — form-key CSRF does not apply.
     * Phase 4 will enforce bearer auth as the real CSRF defense.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        unset($request);
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        unset($request);
        return true;
    }
}
