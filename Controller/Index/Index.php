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
use Magebit\Mcp\Exception\UnauthorizedException;
use Magebit\Mcp\Model\Auth\TokenAuthenticator;
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
 * Per-request pipeline:
 *   1. Origin header (DNS rebinding defense per MCP spec).
 *   2. Bearer authentication — 401 with WWW-Authenticate on failure.
 *   3. Body / JSON-RPC envelope parse.
 *   4. MCP-Protocol-Version header check.
 *   5. Dispatch to the JSON-RPC handler (carrying the auth context).
 *   6. Write response directly on the HTTP response (bypassing layout).
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly Dispatcher $dispatcher,
        private readonly OriginValidator $originValidator,
        private readonly ProtocolVersionValidator $protocolVersionValidator,
        private readonly TokenAuthenticator $authenticator
    ) {
    }

    public function execute(): ResponseInterface
    {
        $origin = $this->header('Origin');
        if (!$this->originValidator->isAllowed($origin)) {
            return $this->jsonRpcError(403, null, ErrorCode::INVALID_ORIGIN, 'Origin not allowed.');
        }

        try {
            $context = $this->authenticator->authenticate($this->header('Authorization'));
        } catch (UnauthorizedException $e) {
            $this->response->setHeader('WWW-Authenticate', 'Bearer realm="Magento MCP"', true);
            return $this->jsonRpcError(401, null, ErrorCode::UNAUTHORIZED, $e->getMessage());
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

        $rpcResponse = $this->dispatcher->dispatch($rpcRequest, $context);

        if ($rpcResponse === null) {
            $this->response->setHttpResponseCode(202);
            $this->response->setHeader('Content-Type', 'application/json', true);
            $this->response->setBody('');
            return $this->response;
        }

        return $this->writeJson(200, $rpcResponse->toArray());
    }

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->request->getServer($key);
        // Some Apache + PHP-FPM / CGI deployments strip the Authorization header
        // unless `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (Apache) or
        // the equivalent fastcgi_param rule is set; the raw header then lands in
        // REDIRECT_HTTP_* instead. Fall back so the module works on default configs.
        if (($value === null || $value === '') && $key === 'HTTP_AUTHORIZATION') {
            $value = $this->request->getServer('REDIRECT_HTTP_AUTHORIZATION');
        }
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
