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
 * Single HTTP endpoint for the Magebit MCP server, reached as `POST /mcp`.
 * The full per-request pipeline is documented in CLAUDE.md.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    // 256 KiB cap — backstop against unauthenticated body-size DoS before auth runs.
    private const MAX_BODY_BYTES = 262144;

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

    public function execute(): ResponseInterface
    {
        $this->seedAuditEnvironment();

        try {
            if (!$this->config->isEnabled()) {
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
                // Recheck after materialization — chunked transfers have no Content-Length.
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

    private function seedAuditEnvironment(): void
    {
        $this->auditContext->protocolVersion = $this->header('Mcp-Protocol-Version');
        $this->auditContext->ipAddress = $this->request->getClientIp();
        $this->auditContext->userAgent = $this->header('User-Agent');
    }

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->request->getServer($key);
        // Apache + PHP-FPM without a SetEnvIf/fastcgi_param rewrite strips
        // Authorization; the raw header lands in REDIRECT_HTTP_* instead.
        if (($value === null || $value === '') && $key === 'HTTP_AUTHORIZATION') {
            $value = $this->request->getServer('REDIRECT_HTTP_AUTHORIZATION');
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * @phpstan-param array<string, mixed> $parsed
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
     * @phpstan-param array<string, mixed> $payload
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

    private function failRpc(int $httpStatus, int|string|null $id, ErrorCode $code, string $message): ResponseInterface
    {
        $this->auditContext->responseStatus = AuditEntryInterface::STATUS_ERROR;
        $this->auditContext->errorCode = (string) $code->value;
        return $this->writeJson($httpStatus, RpcResponse::failure($id, $code, $message)->toArray());
    }

    /**
     * Opt out of form-key CSRF: the only clients are MCP hosts sending
     * `Authorization: Bearer`, and that bearer IS the CSRF gate — browsers
     * refuse to echo bearer auth cross-origin without CORS preflight.
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
