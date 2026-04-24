<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Index;

use InvalidArgumentException;
use JsonException;
use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Exception\UnauthorizedException;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\Auth\TokenAuthenticator;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Dispatcher;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Request as RpcRequest;
use Magebit\Mcp\Model\JsonRpc\Response as RpcResponse;
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
 *   6. Audit row flushed unconditionally via the `finally` block — even
 *      unauthenticated attempts leave a trail.
 *   7. Response written directly on the HTTP response (bypassing layout).
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * JSON-RPC envelopes are small; this cap is the backstop for an
     * unauthenticated attacker posting a multi-megabyte body to exhaust the
     * FPM worker's memory before auth runs. In practice even a
     * `tools/call` with a large `arguments` object fits comfortably.
     */
    private const MAX_BODY_BYTES = 262144;

    /**
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param Dispatcher $dispatcher
     * @param OriginValidator $originValidator
     * @param ProtocolVersionValidator $protocolVersionValidator
     * @param TokenAuthenticator $authenticator
     * @param AuditContext $auditContext
     * @param AuditLogger $auditLogger
     * @param ModuleConfig $config
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly Dispatcher $dispatcher,
        private readonly OriginValidator $originValidator,
        private readonly ProtocolVersionValidator $protocolVersionValidator,
        private readonly TokenAuthenticator $authenticator,
        private readonly AuditContext $auditContext,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfig $config
    ) {
    }

    /**
     * Handle the `POST /mcp` request.
     *
     * @return ResponseInterface
     */
    public function execute(): ResponseInterface
    {
        $this->seedAuditEnvironment();

        try {
            if (!$this->config->isEnabled()) {
                // Log the attempt so operators can see what was blocked while
                // the server is taken offline — but don't reveal the reason
                // beyond a generic "unavailable" signal.
                return $this->failRpc(503, null, ErrorCode::SERVER_DISABLED, 'MCP server is disabled.');
            }

            $origin = $this->header('Origin');
            if (!$this->originValidator->isAllowed($origin)) {
                return $this->failRpc(403, null, ErrorCode::INVALID_ORIGIN, 'Origin not allowed.');
            }

            try {
                $context = $this->authenticator->authenticate($this->header('Authorization'));
            } catch (UnauthorizedException $e) {
                $this->response->setHeader('WWW-Authenticate', 'Bearer realm="Magento MCP"', true);
                return $this->failRpc(401, null, ErrorCode::UNAUTHORIZED, $e->getMessage());
            }

            $this->auditContext->tokenId = $context->token->getId();
            $this->auditContext->adminUserId = $context->getAdminUserId();

            $rawLength = $this->request->getServer('CONTENT_LENGTH');
            $declaredLength = is_scalar($rawLength) ? (int) $rawLength : 0;
            if ($declaredLength > self::MAX_BODY_BYTES) {
                return $this->failRpc(413, null, ErrorCode::INVALID_REQUEST, 'Request body too large.');
            }

            $body = (string) $this->request->getContent();
            if (strlen($body) > self::MAX_BODY_BYTES) {
                // Chunked transfer with no Content-Length, or a lying client —
                // recheck after the body is materialized.
                return $this->failRpc(413, null, ErrorCode::INVALID_REQUEST, 'Request body too large.');
            }

            try {
                $parsed = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return $this->failRpc(400, null, ErrorCode::PARSE_ERROR, 'Parse error: ' . $e->getMessage());
            }

            if (!is_array($parsed)) {
                return $this->failRpc(400, null, ErrorCode::INVALID_REQUEST, 'Request body must be a JSON object.');
            }
            /** @var array<string, mixed> $parsed */

            $this->auditContext->requestId = $this->extractId($parsed);

            try {
                $rpcRequest = RpcRequest::fromArray($parsed);
            } catch (InvalidArgumentException $e) {
                return $this->failRpc(
                    400,
                    $this->auditContext->requestId,
                    ErrorCode::INVALID_REQUEST,
                    $e->getMessage()
                );
            }

            $this->auditContext->method = $rpcRequest->method;

            if ($rpcRequest->method !== 'initialize') {
                $version = $this->header('Mcp-Protocol-Version');
                if ($version !== null && !$this->protocolVersionValidator->isSupported($version)) {
                    return $this->failRpc(
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

            if ($rpcResponse->error !== null) {
                $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
                $this->auditContext->errorCode = (string) $rpcResponse->error->code->value;
            }

            return $this->writeJson(200, $rpcResponse->toArray());
        } finally {
            $this->auditLogger->write($this->auditContext);
        }
    }

    /**
     * Populate the environment fields visible to every request, regardless of
     * whether auth or parsing succeeds. The DTO's default `method` is already
     * the `(request)` placeholder (see {@see AuditContext::METHOD_UNPARSED});
     * the JSON-RPC handler overwrites it once the envelope is parsed.
     */
    private function seedAuditEnvironment(): void
    {
        $this->auditContext->protocolVersion = $this->header('Mcp-Protocol-Version');
        $this->auditContext->ipAddress = $this->request->getClientIp();
        $this->auditContext->userAgent = $this->header('User-Agent');
    }

    /**
     * Read an HTTP header with the Apache/FPM `REDIRECT_HTTP_AUTHORIZATION` fallback.
     *
     * @param string $name
     * @return string|null
     */
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
     * Pull the JSON-RPC `id` out of a raw parsed envelope for early audit tagging.
     *
     * @param array $parsed
     * @phpstan-param array<string, mixed> $parsed
     * @return int|string|null
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
     * Serialize a JSON-RPC payload onto the HTTP response.
     *
     * @param int $status
     * @param array $payload
     * @phpstan-param array<string, mixed> $payload
     * @return ResponseInterface
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

    /**
     * Build an error response and stamp audit status in one place.
     *
     * @param int $httpStatus
     * @param int|string|null $id
     * @param ErrorCode $code
     * @param string $message
     * @return ResponseInterface
     */
    private function failRpc(int $httpStatus, int|string|null $id, ErrorCode $code, string $message): ResponseInterface
    {
        $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        $this->auditContext->errorCode = (string) $code->value;
        return $this->writeJson($httpStatus, RpcResponse::failure($id, $code, $message)->toArray());
    }

    /**
     * Opt out of form-key CSRF enforcement.
     *
     * This endpoint is not reachable via a browser form submission — the only
     * clients are MCP hosts talking JSON-RPC over HTTP with a
     * `Authorization: Bearer` header. A bearer token cannot be forged across
     * origins (browsers refuse to echo bearer auth to arbitrary targets
     * without CORS preflight), so the bearer itself is the CSRF defense.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        unset($request);
        return null;
    }

    /**
     * Short-circuit form-key validation.
     *
     * See {@see createCsrfValidationException}. The bearer authentication in
     * {@see execute} is the real access control.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        unset($request);
        return true;
    }
}
