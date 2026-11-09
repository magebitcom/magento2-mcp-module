<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Index;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Controller\Index\Index;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\TokenAuthenticator;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Dispatcher;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Request as RpcRequest;
use Magebit\Mcp\Model\JsonRpc\Response as RpcResponse;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magebit\Mcp\Model\Validator\OriginValidator;
use Magebit\Mcp\Model\Validator\ProtocolVersionValidator;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * The `server/discover` probe of newer MCP clients leaves no audit row; other unknown methods still audit as error.
 */
class IndexAuditTest extends TestCase
{
    public function testServerDiscoverProbeIsAnsweredButNotAudited(): void
    {
        [$written, $body] = $this->post('server/discover', $this->methodNotFound('server/discover'));

        self::assertSame([], $written);
        self::assertStringContainsString('"code":-32601', $body);
    }

    public function testOtherUnknownMethodIsStillAuditedAsError(): void
    {
        [$written] = $this->post('foo/bar', $this->methodNotFound('foo/bar'));

        self::assertCount(1, $written);
        self::assertSame('foo/bar', $written[0]->method);
        self::assertSame(AuditEntryInterface::STATUS_ERROR, $written[0]->responseStatus);
        self::assertSame('-32601', $written[0]->errorCode);
    }

    public function testServerDiscoverWithAnotherErrorIsStillAudited(): void
    {
        $failure = static fn (RpcRequest $r): RpcResponse =>
            RpcResponse::failure($r->id, ErrorCode::INTERNAL_ERROR, 'Internal error.');

        [$written] = $this->post('server/discover', $failure);

        self::assertCount(1, $written);
        self::assertSame('-32603', $written[0]->errorCode);
    }

    public function testSuccessfulCallIsAuditedAsOk(): void
    {
        [$written] = $this->post(
            'ping',
            static fn (RpcRequest $r): RpcResponse => RpcResponse::success($r->id, [])
        );

        self::assertCount(1, $written);
        self::assertSame('ok', $written[0]->responseStatus);
    }

    /**
     * @param string $method
     * @return callable(RpcRequest): RpcResponse
     */
    private function methodNotFound(string $method): callable
    {
        return static fn (RpcRequest $r): RpcResponse => RpcResponse::failure(
            $r->id,
            ErrorCode::METHOD_NOT_FOUND,
            sprintf('Method "%s" not found.', $method)
        );
    }

    /**
     * @param string $method
     * @param callable(RpcRequest): RpcResponse $answer
     * @return array{0: list<AuditContext>, 1: string}
     */
    private function post(string $method, callable $answer): array
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => new \stdClass()]);
        self::assertIsString($payload);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getServer')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'HTTP_AUTHORIZATION' => 'Bearer test',
                'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
                'CONTENT_LENGTH' => (string) strlen($payload),
                default => null,
            }
        );
        $request->method('getContent')->willReturn($payload);

        $body = '';
        $response = $this->createMock(HttpResponse::class);
        $response->method('setBody')->willReturnCallback(
            function (string $value) use (&$body, $response): HttpResponse {
                $body = $value;
                return $response;
            }
        );

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static fn (RpcRequest $r): RpcResponse => $answer($r)
        );

        $origin = $this->createMock(OriginValidator::class);
        $origin->method('isAllowed')->willReturn(true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getId')->willReturn(7);
        $authenticator = $this->createMock(TokenAuthenticator::class);
        $authenticator->method('authenticate')->willReturn(
            new AuthenticatedContext($token, $this->createMock(User::class))
        );

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getMaxBodyBytes')->willReturn(262144);

        $written = [];
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('write')->willReturnCallback(
            static function (AuditContext $context) use (&$written): void {
                $written[] = clone $context;
            }
        );

        $controller = new Index(
            $request,
            $response,
            $dispatcher,
            $origin,
            new ProtocolVersionValidator(),
            $authenticator,
            new AuditContext(),
            $auditLogger,
            $config,
            $this->createMock(PublicUrlBuilder::class)
        );
        $controller->execute();

        return [$written, $body];
    }
}
