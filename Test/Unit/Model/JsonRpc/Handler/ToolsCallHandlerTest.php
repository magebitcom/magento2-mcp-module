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

        // $this->fail() stamps the audit context so rate-limited calls land
        // in the admin audit grid with the right error code.
        $this->assertSame('-32013', $auditContext->errorCode);
    }
}
