<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
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
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\ToolAvailability;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;
use Magebit\Mcp\Model\Validator\ArgumentCoercer;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Acl;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * A tool whose optional feature is missing answers like an unregistered tool, for every role.
 */
class ToolAvailabilityGateTest extends TestCase
{
    private const DECLARED = 'Magento_Cms::page';
    private const UNDECLARED = 'Magebit_McpHyvaCommerceTools::feature_unavailable';

    public function testUnavailableToolIsNotRegisteredForFullAdministrator(): void
    {
        $tool = $this->availabilityTool('hyva_form.form.list', false);

        $response = $this->call($tool, 'hyva_form_form_list', aclAllow: true);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::TOOL_NOT_FOUND, $response->error->code);
        self::assertSame('Tool "hyva_form_form_list" is not registered.', $response->error->message);
    }

    public function testUndeclaredUnderlyingResourceIsNotRegisteredInsteadOfRoleRefusal(): void
    {
        $tool = $this->underlyingTool('hyva_form.form.list', self::UNDECLARED);

        $response = $this->call($tool, 'hyva_form_form_list', aclAllow: true);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::TOOL_NOT_FOUND, $response->error->code);
    }

    public function testUnavailableIsCheckedBeforeTheTokenScope(): void
    {
        $tool = $this->availabilityTool('hyva_form.form.list', false);

        $response = $this->call($tool, 'hyva_form.form.list', aclAllow: true, scopes: ['system.whoami']);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::TOOL_NOT_FOUND, $response->error->code);
    }

    public function testDeclaredButDeniedUnderlyingResourceStillBlamesTheRole(): void
    {
        $tool = $this->underlyingTool('cms.page.list', self::DECLARED);

        $response = $this->call($tool, 'cms_page_list', aclAllow: true, denied: [self::DECLARED]);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        self::assertSame('Your admin role does not permit the underlying Magento action.', $response->error->message);
    }

    public function testAvailableToolsRunUnchanged(): void
    {
        foreach ([
            $this->plainTool('system.whoami'),
            $this->availabilityTool('hyva_form.form.list', true),
            $this->underlyingTool('cms.page.list', self::DECLARED),
        ] as $tool) {
            $response = $this->call($tool, str_replace('.', '_', $tool->getName()), aclAllow: true);
            self::assertNull($response->error, $tool->getName());
        }
    }

    public function testHandlersWithoutTheAvailabilityServiceKeepTheOldBehaviour(): void
    {
        $tool = $this->availabilityTool('hyva_form.form.list', false);

        self::assertNull($this->call($tool, 'hyva_form_form_list', aclAllow: true, wired: false)->error);
        self::assertSame(['hyva_form_form_list'], $this->listNames([$tool], wired: false));
    }

    public function testToolsListHidesUnavailableTools(): void
    {
        $names = $this->listNames([
            $this->plainTool('system.whoami'),
            $this->availabilityTool('hyva_form.form.list', false),
            $this->availabilityTool('hyva_form.form.get', true),
            $this->underlyingTool('hyva_menu.list', self::UNDECLARED),
            $this->underlyingTool('cms.page.list', self::DECLARED),
        ]);

        self::assertSame(['system_whoami', 'hyva_form_form_get', 'cms_page_list'], $names);
    }

    /**
     * @param ToolInterface $tool
     * @param string $requested
     * @param bool $aclAllow
     * @param array|null $scopes
     * @param array $denied
     * @param bool $wired
     * @phpstan-param list<string>|null $scopes
     * @phpstan-param list<string> $denied
     * @return Response
     */
    private function call(
        ToolInterface $tool,
        string $requested,
        bool $aclAllow,
        ?array $scopes = null,
        array $denied = [],
        bool $wired = true
    ): Response {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('getCanonicalName')->willReturn($tool->getName());
        $registry->method('get')->willReturn($tool);

        $coercer = $this->createMock(ArgumentCoercer::class);
        $coercer->method('coerce')->willReturnArgument(1);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturn(['type' => 'object']);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('isDisabled')->willReturn(false);

        $handler = new ToolsCallHandler(
            $registry,
            $this->aclChecker($aclAllow, $denied),
            $this->createMock(JsonSchemaValidator::class),
            $this->createMock(RateLimiterInterface::class),
            $this->createMock(EventManager::class),
            $this->createMock(ModuleConfig::class),
            new AuditContext(),
            new AuthenticatedContextProvider(),
            $this->createMock(LoggerInterface::class),
            $coercer,
            $sanitizer,
            $disabled,
            $wired ? $this->toolAvailability() : null
        );

        return $handler->handle(
            new Request(1, false, 'tools/call', ['name' => $requested, 'arguments' => []]),
            new AuthenticatedContext($this->token($scopes), $this->createMock(User::class))
        );
    }

    /**
     * @param array $tools
     * @param bool $wired
     * @phpstan-param list<ToolInterface> $tools
     * @return list<string>
     */
    private function listNames(array $tools, bool $wired = true): array
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($byName);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitizeForClient')->willReturn(['type' => 'object']);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('isDisabled')->willReturn(false);
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);

        $handler = new ToolsListHandler(
            $registry,
            $this->aclChecker(true, []),
            $config,
            $sanitizer,
            $this->createMock(LoggerInterface::class),
            new ToolDomain([]),
            $disabled,
            $wired ? $this->toolAvailability() : null
        );
        $response = $handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->token(null), $this->createMock(User::class))
        );
        self::assertNotNull($response->result);
        $listed = $response->result['tools'] ?? [];
        self::assertIsArray($listed);
        $names = [];
        foreach ($listed as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['name'] ?? null);
            $names[] = $entry['name'];
        }
        return $names;
    }

    /**
     * @param bool $allow
     * @param array $denied
     * @phpstan-param list<string> $denied
     * @return AclChecker
     */
    private function aclChecker(bool $allow, array $denied): AclChecker
    {
        $checker = $this->createMock(AclChecker::class);
        $checker->method('isAllowed')->willReturnCallback(
            static fn (User $_u, string $resource): bool => $allow
                && $resource !== self::UNDECLARED
                && !in_array($resource, $denied, true)
        );
        return $checker;
    }

    /**
     * @return ToolAvailability
     */
    private function toolAvailability(): ToolAvailability
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('hasResource')->willReturnCallback(
            static fn (mixed $resource): bool => $resource !== self::UNDECLARED
        );
        $builder = $this->createMock(AclBuilder::class);
        $builder->method('getAcl')->willReturn($acl);
        return new ToolAvailability($builder);
    }

    /**
     * @param array|null $scopes
     * @phpstan-param list<string>|null $scopes
     * @return TokenInterface
     */
    private function token(?array $scopes): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn($scopes);
        $token->method('getAllowWrites')->willReturn(true);
        return $token;
    }

    /**
     * @param string $name
     * @return ToolInterface
     */
    private function plainTool(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $this->stub($tool, $name);
        return $tool;
    }

    /**
     * @param string $name
     * @param bool $available
     * @return ToolInterface
     */
    private function availabilityTool(string $name, bool $available): ToolInterface
    {
        $tool = $this->createMock(AvailabilityGateToolFixtureInterface::class);
        $this->stub($tool, $name);
        $tool->method('isAvailable')->willReturn($available);
        return $tool;
    }

    /**
     * @param string $name
     * @param string $resource
     * @return ToolInterface
     */
    private function underlyingTool(string $name, string $resource): ToolInterface
    {
        $tool = $this->createMock(UnderlyingGateToolFixtureInterface::class);
        $this->stub($tool, $name);
        $tool->method('getUnderlyingAclResource')->willReturn($resource);
        return $tool;
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject&ToolInterface $tool
     * @param string $name
     * @return void
     */
    private function stub($tool, string $name): void
    {
        $tool->method('getName')->willReturn($name);
        $tool->method('getTitle')->willReturn($name);
        $tool->method('getDescription')->willReturn($name);
        $tool->method('getAclResource')->willReturn('Magebit_Mcp::tool');
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $result = $this->createMock(ToolResultInterface::class);
        $result->method('getContent')->willReturn([['type' => 'text', 'text' => 'ok']]);
        $result->method('isError')->willReturn(false);
        $result->method('getAuditSummary')->willReturn([]);
        $tool->method('execute')->willReturn($result);
    }
}

interface AvailabilityGateToolFixtureInterface extends ToolInterface, AvailabilityAwareInterface
{
}

interface UnderlyingGateToolFixtureInterface extends ToolInterface, UnderlyingAclAwareInterface
{
}
