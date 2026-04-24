<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc;

use InvalidArgumentException;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\Dispatcher;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class DispatcherTest extends TestCase
{
    private function context(): AuthenticatedContext
    {
        return new AuthenticatedContext(
            $this->createStub(TokenInterface::class),
            $this->createStub(User::class)
        );
    }

    /**
     * @param string $method JSON-RPC method name the stub handler claims
     */
    private function stubHandler(string $method): HandlerInterface&MockObject
    {
        $handler = $this->createMock(HandlerInterface::class);
        // `method()` is both PHPUnit's builder and the interface's contract;
        // the full `expects()->method()` form disambiguates.
        $handler->expects($this->any())->method('method')->willReturn($method);
        return $handler;
    }

    public function testRoutesToMatchingHandler(): void
    {
        $ctx = $this->context();
        $request = new Request(1, false, 'foo', []);
        $expected = Response::success(1, ['ok' => true]);

        $handler = $this->stubHandler('foo');
        $handler->expects($this->once())
            ->method('handle')
            ->with($request, $ctx)
            ->willReturn($expected);

        $dispatcher = new Dispatcher([$handler], $this->createStub(LoggerInterface::class));

        $this->assertSame($expected, $dispatcher->dispatch($request, $ctx));
    }

    public function testReturnsMethodNotFoundForUnknownRequest(): void
    {
        $dispatcher = new Dispatcher([], $this->createStub(LoggerInterface::class));

        $response = $dispatcher->dispatch(new Request(7, false, 'missing', []), $this->context());

        $this->assertNotNull($response);
        $this->assertSame(7, $response->id);
        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::METHOD_NOT_FOUND, $response->error->code);
    }

    public function testSwallowsUnknownNotification(): void
    {
        $dispatcher = new Dispatcher([], $this->createStub(LoggerInterface::class));

        $response = $dispatcher->dispatch(
            new Request(null, true, 'some/notification', []),
            $this->context()
        );

        $this->assertNull($response);
    }

    public function testWrapsHandlerExceptionInInternalError(): void
    {
        $handler = $this->stubHandler('boom');
        $handler->expects($this->any())->method('handle')->willThrowException(new RuntimeException('kaboom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dispatcher = new Dispatcher([$handler], $logger);

        $response = $dispatcher->dispatch(new Request('req-1', false, 'boom', []), $this->context());

        $this->assertNotNull($response);
        $this->assertSame('req-1', $response->id);
        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::INTERNAL_ERROR, $response->error->code);
    }

    public function testSwallowsHandlerExceptionOnNotification(): void
    {
        $handler = $this->stubHandler('boom');
        $handler->expects($this->any())->method('handle')->willThrowException(new RuntimeException('kaboom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dispatcher = new Dispatcher([$handler], $logger);

        $response = $dispatcher->dispatch(new Request(null, true, 'boom', []), $this->context());

        $this->assertNull($response);
    }

    public function testRejectsNonHandlerInstance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/handlers must implement/');

        /** @var array<int, HandlerInterface> $bogusHandlers */
        $bogusHandlers = [new stdClass()];
        new Dispatcher($bogusHandlers, $this->createStub(LoggerInterface::class));
    }

    public function testRejectsDuplicateMethodRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate.+dup/');

        new Dispatcher(
            [$this->stubHandler('dup'), $this->stubHandler('dup')],
            $this->createStub(LoggerInterface::class)
        );
    }
}
