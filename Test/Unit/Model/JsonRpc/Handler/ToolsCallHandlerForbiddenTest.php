<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\AuditEntryInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsCallHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\ArgumentCoercer;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ToolsCallHandlerForbiddenTest extends TestCase
{
    /**
     * @var AuditContext
     */
    private AuditContext $auditContext;

    /**
     * @var LoggerInterface&MockObject
     */
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->auditContext = new AuditContext();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testForbiddenExceptionAnswersForbiddenWithMessageAndData(): void
    {
        $this->logger->expects($this->never())->method('error');

        $response = $this->call(new ForbiddenException(
            'Page 7 belongs to a store outside your admin scope.',
            ['reason' => 'store_scope', 'page_id' => 7]
        ));

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame('Page 7 belongs to a store outside your admin scope.', $response->error->message);
        $this->assertSame(['reason' => 'store_scope', 'page_id' => 7], $response->error->data);
    }

    public function testForbiddenExceptionIsAuditedLikeThePreExecutionRefusals(): void
    {
        $this->call(new ForbiddenException('Not in your scope.'));

        $this->assertSame((string) ErrorCode::FORBIDDEN->value, $this->auditContext->errorCode);
        $this->assertSame('-32004', $this->auditContext->errorCode);
        $this->assertSame(AuditEntryInterface::STATUS_ERROR, $this->auditContext->responseStatus);
    }

    public function testForbiddenExceptionWithoutDataOmitsErrorData(): void
    {
        $response = $this->call(new ForbiddenException('Not in your scope.'));

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertNull($response->error->data);
    }

    public function testLocalizedExceptionStillAnswersToolExecutionFailed(): void
    {
        $this->logger->expects($this->never())->method('error');

        $response = $this->call(new LocalizedException(new Phrase('The page could not be saved.')));

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::TOOL_EXECUTION_FAILED, $response->error->code);
        $this->assertSame('The page could not be saved.', $response->error->message);
        $this->assertSame('-32011', $this->auditContext->errorCode);
    }

    public function testUnexpectedExceptionStillAnswersGenericFailureAndIsLogged(): void
    {
        $this->logger->expects($this->once())->method('error');

        $response = $this->call(new RuntimeException('SQLSTATE[42S02]: table missing'));

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::TOOL_EXECUTION_FAILED, $response->error->code);
        $this->assertSame('Tool execution failed.', $response->error->message);
        $this->assertSame('-32011', $this->auditContext->errorCode);
    }

    /**
     * @param Throwable $thrown
     * @return Response
     */
    private function call(Throwable $thrown): Response
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('system.store.info');
        $tool->method('getAclResource')->willReturn('Magebit_Mcp::tool_system_store_info');
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->once())->method('execute')->willThrowException($thrown);

        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')->willReturn('system.store.info');
        $toolRegistry->method('get')->willReturn($tool);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);

        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(1);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn(true);

        $coercer = $this->createMock(ArgumentCoercer::class);
        $coercer->method('coerce')->willReturnArgument(1);

        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturn(['type' => 'object']);

        $disabledTools = $this->createMock(DisabledTools::class);
        $disabledTools->method('isDisabled')->willReturn(false);

        $handler = new ToolsCallHandler(
            $toolRegistry,
            $aclChecker,
            $this->createMock(JsonSchemaValidator::class),
            $this->createMock(RateLimiterInterface::class),
            $this->createMock(EventManager::class),
            $this->createMock(ModuleConfig::class),
            $this->auditContext,
            new AuthenticatedContextProvider(),
            $this->logger,
            $coercer,
            $sanitizer,
            $disabledTools
        );

        return $handler->handle(
            new Request(3, false, 'tools/call', ['name' => 'system.store.info', 'arguments' => []]),
            new AuthenticatedContext($token, $adminUser)
        );
    }
}
