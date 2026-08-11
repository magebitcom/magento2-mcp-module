<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
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
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the third ACL gate — {@see ContextualAclAwareInterface} — in `ToolsCallHandler::checkAcl()`.
 */
class ToolsCallHandlerContextualAclTest extends TestCase
{
    private const TOOL_NAME = 'system.config.set';
    private const OWN_RESOURCE = 'Magebit_Mcp::tool_system_config_set';
    private const UNDERLYING_RESOURCE = 'Magento_Config::config';
    private const TAX_RESOURCE = 'Magento_Tax::config_tax';
    private const UNRESOLVED_MESSAGE =
        'Could not determine which Magento permission this call requires; the call was refused.';

    /**
     * ACL resources the checker will grant; everything else is denied.
     *
     * @var array<int, string>
     */
    private array $allowedResources = [];

    /**
     * Every resource `AclChecker::isAllowed()` was asked about, in call order.
     *
     * @var array<int, string>
     */
    private array $checkedResources = [];

    /**
     * @var AclChecker&MockObject
     */
    private $aclChecker;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var AuditContext
     */
    private AuditContext $auditContext;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->allowedResources = [];
        $this->checkedResources = [];
        $this->auditContext = new AuditContext();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->aclChecker = $this->createMock(AclChecker::class);
        $this->aclChecker->method('isAllowed')->willReturnCallback(
            function (User $user, string $resource): bool {
                $this->checkedResources[] = $resource;
                return in_array($resource, $this->allowedResources, true);
            }
        );
    }

    public function testContextualResourceDenialProducesForbidden(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->contextualTool([self::TAX_RESOURCE]);
        $tool->expects($this->never())->method('execute');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(
            'Your admin role does not permit the Magento section this call targets.',
            $response->error->message
        );
        $this->assertSame('-32004', $this->auditContext->errorCode);
        $this->assertContains(self::TAX_RESOURCE, $this->checkedResources);
    }

    public function testContextualResourceGrantAllowsTheCall(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE, self::TAX_RESOURCE];
        $tool = $this->contextualTool([self::TAX_RESOURCE]);
        $tool->expects($this->once())->method('execute')->willReturn($this->successResult());

        $response = $this->dispatch($tool);

        $this->assertNull($response->error);
        $this->assertSame([self::OWN_RESOURCE, self::TAX_RESOURCE], $this->checkedResources);
    }

    public function testEmptyContextualResourceListAddsNoGate(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->contextualTool([]);
        $tool->expects($this->once())->method('execute')->willReturn($this->successResult());

        $response = $this->dispatch($tool);

        $this->assertNull($response->error);
        $this->assertSame([self::OWN_RESOURCE], $this->checkedResources);
    }

    public function testEveryContextualResourceIsCheckedNotJustTheFirst(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE, 'Magento_Backend::currency'];
        $tool = $this->contextualTool(['Magento_Backend::currency', self::TAX_RESOURCE]);
        $tool->expects($this->never())->method('execute');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(
            [self::OWN_RESOURCE, 'Magento_Backend::currency', self::TAX_RESOURCE],
            $this->checkedResources
        );
    }

    public function testContextualDenialRefusesEvenWhenOwnAndUnderlyingAclPass(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE, self::UNDERLYING_RESOURCE];
        $tool = $this->contextualTool([self::TAX_RESOURCE], self::UNDERLYING_RESOURCE);
        $tool->expects($this->never())->method('execute');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(
            'Your admin role does not permit the Magento section this call targets.',
            $response->error->message
        );
    }

    public function testUnderlyingAclStillShortCircuitsBeforeTheContextualGate(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE, self::TAX_RESOURCE];
        $tool = $this->createMock(UnderlyingContextualToolFixtureInterface::class);
        $this->stubToolBasics($tool);
        $tool->method('getUnderlyingAclResource')->willReturn(self::UNDERLYING_RESOURCE);
        $tool->expects($this->never())->method('execute');
        $tool->expects($this->never())->method('getContextualAclResources');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(
            'Your admin role does not permit the underlying Magento action.',
            $response->error->message
        );
    }

    public function testContextualResourcesAreResolvedFromTheCallArguments(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE, self::TAX_RESOURCE];
        $received = null;

        $tool = $this->createMock(ContextualToolFixtureInterface::class);
        $this->stubToolBasics($tool);
        $tool->method('getContextualAclResources')->willReturnCallback(
            function (array $arguments) use (&$received): array {
                $received = $arguments;
                return [self::TAX_RESOURCE];
            }
        );
        $tool->expects($this->once())->method('execute')->willReturn($this->successResult());

        $response = $this->dispatch($tool, ['path' => 'tax/classes/shipping_tax_class', 'value' => '2']);

        $this->assertNull($response->error);
        $this->assertSame(['path' => 'tax/classes/shipping_tax_class', 'value' => '2'], $received);
    }

    public function testBlankContextualResourceIsDeniedNotSkipped(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->contextualTool(['']);
        $tool->expects($this->never())->method('execute');
        $this->logger->expects($this->once())->method('error');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(self::UNRESOLVED_MESSAGE, $response->error->message);
        $this->assertSame('-32004', $this->auditContext->errorCode);
        $this->assertSame([self::OWN_RESOURCE], $this->checkedResources);
    }

    public function testResolverThrowIsDeniedRatherThanSurfacedAsAnInternalError(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->createMock(ContextualToolFixtureInterface::class);
        $this->stubToolBasics($tool);
        $tool->method('getContextualAclResources')
            ->willThrowException(new LocalizedException(__('Unknown config section "nosuchsection".')));
        $tool->expects($this->never())->method('execute');
        $this->logger->expects($this->once())->method('error');

        $response = $this->dispatch($tool, ['path' => 'nosuchsection/foo/bar']);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(self::UNRESOLVED_MESSAGE, $response->error->message);
        $this->assertSame('-32004', $this->auditContext->errorCode);
    }

    public function testNonStringContextualResourceIsDeniedRatherThanFatal(): void
    {
        // `AclChecker::isAllowed()` takes a string under strict_types, so a malformed entry
        // raises a TypeError mid-loop — it must land on the same refusal.
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->createMock(ContextualToolFixtureInterface::class);
        $this->stubToolBasics($tool);
        $tool->method('getContextualAclResources')->willReturn([12345]);
        $tool->expects($this->never())->method('execute');
        $this->logger->expects($this->once())->method('error');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        $this->assertSame(self::UNRESOLVED_MESSAGE, $response->error->message);
    }

    public function testDenialForARealPermissionFailureKeepsItsOwnMessage(): void
    {
        // The two refusals must stay distinguishable: a role problem must not be reported as a
        // tool bug, and vice versa.
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->contextualTool([self::TAX_RESOURCE]);
        $this->logger->expects($this->never())->method('error');

        $response = $this->dispatch($tool);

        $this->assertNotNull($response->error);
        $this->assertNotSame(self::UNRESOLVED_MESSAGE, $response->error->message);
    }

    public function testToolWithoutTheInterfaceGainsNoExtraAclCheck(): void
    {
        $this->allowedResources = [self::OWN_RESOURCE];
        $tool = $this->createMock(ToolInterface::class);
        $this->stubToolBasics($tool);
        $tool->expects($this->once())->method('execute')->willReturn($this->successResult());

        $response = $this->dispatch($tool);

        $this->assertNull($response->error);
        $this->assertSame([self::OWN_RESOURCE], $this->checkedResources);
    }

    /**
     * @param array<int, string> $contextualResources
     * @param string|null $underlyingResource
     * @return ContextualToolFixtureInterface&MockObject
     */
    private function contextualTool(array $contextualResources, ?string $underlyingResource = null)
    {
        $class = $underlyingResource === null
            ? ContextualToolFixtureInterface::class
            : UnderlyingContextualToolFixtureInterface::class;

        $tool = $this->createMock($class);
        $this->stubToolBasics($tool);
        $tool->method('getContextualAclResources')->willReturn($contextualResources);
        if ($underlyingResource !== null) {
            $tool->method('getUnderlyingAclResource')->willReturn($underlyingResource);
        }

        return $tool;
    }

    /**
     * @param ToolInterface&MockObject $tool
     * @return void
     */
    private function stubToolBasics($tool): void
    {
        $tool->method('getName')->willReturn(self::TOOL_NAME);
        $tool->method('getAclResource')->willReturn(self::OWN_RESOURCE);
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
    }

    /**
     * @return ToolResultInterface&MockObject
     */
    private function successResult()
    {
        $result = $this->createMock(ToolResultInterface::class);
        $result->method('getContent')->willReturn([['type' => 'text', 'text' => 'ok']]);
        $result->method('isError')->willReturn(false);
        $result->method('getAuditSummary')->willReturn(['updated' => 1]);

        return $result;
    }

    /**
     * @param ToolInterface&MockObject $tool
     * @param array<string, mixed> $arguments
     * @return Response
     */
    private function dispatch($tool, array $arguments = ['path' => 'tax/calculation/price_includes_tax']): Response
    {
        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')->with(self::TOOL_NAME)->willReturn(self::TOOL_NAME);
        $toolRegistry->method('get')->with(self::TOOL_NAME)->willReturn($tool);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $token->method('getAllowWrites')->willReturn(true);

        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(42);

        $coercer = $this->createMock(ArgumentCoercer::class);
        $coercer->method('coerce')->willReturnArgument(1);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturn(['type' => 'object']);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);

        $disabledTools = $this->createMock(DisabledTools::class);
        $disabledTools->method('isDisabled')->willReturn(false);

        $handler = new ToolsCallHandler(
            $toolRegistry,
            $this->aclChecker,
            $this->createMock(JsonSchemaValidator::class),
            $this->createMock(RateLimiterInterface::class),
            $this->createMock(EventManager::class),
            $config,
            $this->auditContext,
            new AuthenticatedContextProvider(),
            $this->logger,
            $coercer,
            $sanitizer,
            $disabledTools
        );

        $request = new Request(
            11,
            false,
            'tools/call',
            ['name' => self::TOOL_NAME, 'arguments' => $arguments]
        );

        return $handler->handle($request, new AuthenticatedContext($token, $adminUser));
    }
}

/**
 * PHPUnit 9 cannot mock an intersection of types, so the fixtures below name the combinations.
 */
interface ContextualToolFixtureInterface extends ToolInterface, ContextualAclAwareInterface
{
}

interface UnderlyingContextualToolFixtureInterface extends
    ContextualToolFixtureInterface,
    UnderlyingAclAwareInterface
{
}
