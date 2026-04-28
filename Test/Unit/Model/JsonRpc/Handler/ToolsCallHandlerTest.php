<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Exception\RateLimitedException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsCallHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ToolsCallHandlerTest extends TestCase
{
    public function testTranslatesRateLimitedExceptionToRateLimitedErrorCode(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getAclResource')->willReturn('Magebit_Mcp::tool_system_store_list');
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);

        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')
            ->with('system.store.list')
            ->willReturn('system.store.list');
        $toolRegistry->method('get')->with('system.store.list')->willReturn($tool);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $token->method('getAllowWrites')->willReturn(true);

        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(42);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn(true);

        $schemaValidator = $this->createMock(JsonSchemaValidator::class);
        $schemaValidator->expects($this->once())->method('validate');

        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('check')
            ->with(42, 'system.store.list')
            ->willThrowException(new RateLimitedException(
                'Rate limit exceeded: 2 requests/minute for "system.store.list".',
                2,
                37
            ));

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->never())->method('dispatch');

        $config = $this->createMock(ModuleConfig::class);
        $auditContext = new AuditContext();

        $handler = new ToolsCallHandler(
            $toolRegistry,
            $aclChecker,
            $schemaValidator,
            $rateLimiter,
            $eventManager,
            $config,
            $auditContext,
            $this->createMock(LoggerInterface::class)
        );

        $request = new Request(
            7,
            false,
            'tools/call',
            ['name' => 'system.store.list', 'arguments' => []]
        );

        $response = $handler->handle(
            $request,
            new AuthenticatedContext($token, $adminUser)
        );

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::RATE_LIMITED, $response->error->code);
        $this->assertSame(
            'Rate limit exceeded: 2 requests/minute for "system.store.list".',
            $response->error->message
        );
        $this->assertSame(
            ['limit' => 2, 'retry_after_seconds' => 37],
            $response->error->data
        );

        $this->assertSame('-32013', $auditContext->errorCode);
    }

    public function testAcceptsUnderscoredWireNameAndDispatchesToCanonicalTool(): void
    {
        // Claude.ai sends `system_store_list` (its frontend rejects dots).
        // The handler must resolve that to the canonical `system.store.list`
        // tool, run scope/ACL/audit using the canonical name, and execute
        // successfully.
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('system.store.list');
        $tool->method('getAclResource')->willReturn('Magebit_Mcp::tool_system_store_list');
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $result = $this->createMock(ToolResultInterface::class);
        $result->method('getContent')->willReturn([['type' => 'text', 'text' => 'ok']]);
        $result->method('isError')->willReturn(false);
        $result->method('getAuditSummary')->willReturn(['count' => 0]);
        $tool->method('execute')->willReturn($result);

        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')
            ->with('system_store_list')
            ->willReturn('system.store.list');
        $toolRegistry->method('get')->with('system.store.list')->willReturn($tool);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(['system.store.list']);
        $token->method('getAllowWrites')->willReturn(false);

        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(7);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn(true);

        $schemaValidator = $this->createMock(JsonSchemaValidator::class);
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('check');
        $eventManager = $this->createMock(EventManager::class);
        $config = $this->createMock(ModuleConfig::class);
        $auditContext = new AuditContext();

        $handler = new ToolsCallHandler(
            $toolRegistry,
            $aclChecker,
            $schemaValidator,
            $rateLimiter,
            $eventManager,
            $config,
            $auditContext,
            $this->createMock(LoggerInterface::class)
        );

        $request = new Request(
            8,
            false,
            'tools/call',
            ['name' => 'system_store_list', 'arguments' => []]
        );

        $response = $handler->handle(
            $request,
            new AuthenticatedContext($token, $adminUser)
        );

        $this->assertNull($response->error);
        $this->assertNotNull($response->result);
        $this->assertSame('system.store.list', $auditContext->toolName);
    }

    public function testReturnsToolNotFoundWhenWireNameDoesNotResolve(): void
    {
        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')->willReturn(null);
        $toolRegistry->method('get')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(1);

        $auditContext = new AuditContext();
        $handler = new ToolsCallHandler(
            $toolRegistry,
            $this->createMock(AclChecker::class),
            $this->createMock(JsonSchemaValidator::class),
            $this->createMock(RateLimiterInterface::class),
            $this->createMock(EventManager::class),
            $this->createMock(ModuleConfig::class),
            $auditContext,
            $this->createMock(LoggerInterface::class)
        );

        $request = new Request(
            9,
            false,
            'tools/call',
            ['name' => 'totally_unknown', 'arguments' => []]
        );

        $response = $handler->handle($request, new AuthenticatedContext($token, $adminUser));

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::TOOL_NOT_FOUND, $response->error->code);
        // Audit row preserves the requested wire name so operators can see what
        // the client actually asked for, not a translated form.
        $this->assertSame('totally_unknown', $auditContext->toolName);
    }
}
