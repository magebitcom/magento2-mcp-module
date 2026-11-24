<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\RateLimiterInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobToolAccess;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsCallHandler;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\Response;
use Magebit\Mcp\Model\Validator\ArgumentCoercer;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magebit\Mcp\Tool\System\Job\Get;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The outer role gate of the `system.job.*` tools in both `tools/list` and `tools/call`: a role passes it with
 * `Magebit_Mcp::tools` or with the resource of an async-capable bulk tool, even one outside `Magebit_Mcp::tools`.
 * The per-job checks inside the tools still apply.
 */
class JobToolRoleGateTest extends TestCase
{
    private const ASYNC_TOOL = 'tax.rate.bulk_import';
    private const ASYNC_RESOURCE = 'Magento_Tax::manage_tax';
    private const OTHER_TOOL = 'catalog.product.bulk_update';
    private const OTHER_RESOURCE = 'Magebit_Mcp::mcp_tool_catalog_product_bulk_update';
    private const JOB_TOOLS = ['system_job_get', 'system_job_list'];

    /** @var JobRepository&MockObject */
    private $jobs;
    /** @var array<string, ToolInterface> */
    private array $tools = [];
    private AuthenticatedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(JobRepository::class);
        $this->jobs->method('getItems')->willReturn(['items' => [], 'total' => 0]);
        $this->contextProvider = new AuthenticatedContextProvider();
    }

    public function testRoleWithOnlyAnAsyncBulkToolResourceOutsideTheToolsTreeSeesTheJobTools(): void
    {
        $names = $this->listNames([self::ASYNC_RESOURCE], asyncCapable: true);

        self::assertSame([...self::JOB_TOOLS, 'tax_rate_bulk_import'], $names);
    }

    public function testRoleWithOnlyAnAsyncBulkToolResourceCanCallTheJobTools(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job(self::ASYNC_TOOL));

        $response = $this->call('system_job_get', [self::ASYNC_RESOURCE], asyncCapable: true);

        self::assertNull($response->error);
        self::assertNotNull($response->result);
    }

    public function testPerJobChecksStillApplyForARoleAdmittedThroughAnAsyncBulkTool(): void
    {
        $this->jobs->method('getByJobId')->willReturn($this->job(self::OTHER_TOOL));

        $response = $this->call('system_job_get', [self::ASYNC_RESOURCE], asyncCapable: true);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        self::assertStringContainsString('catalog_product_bulk_update', $response->error->message);
    }

    public function testRoleWithNeitherResourceDoesNotSeeTheJobTools(): void
    {
        self::assertSame([], $this->listNames([], asyncCapable: true));
    }

    public function testRoleWithNeitherResourceIsRefusedTheJobTools(): void
    {
        $response = $this->call('system_job_get', [], asyncCapable: true);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        self::assertSame('Your admin role does not permit this tool.', $response->error->message);
    }

    public function testRoleWithOnlyASyncOnlyBulkToolResourceDoesNotSeeTheJobTools(): void
    {
        self::assertSame(['tax_rate_bulk_import'], $this->listNames([self::ASYNC_RESOURCE], asyncCapable: false));
    }

    public function testRoleWithOnlyASyncOnlyBulkToolResourceIsRefusedTheJobTools(): void
    {
        $response = $this->call('system_job_get', [self::ASYNC_RESOURCE], asyncCapable: false);

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::FORBIDDEN, $response->error->code);
        self::assertSame('Your admin role does not permit this tool.', $response->error->message);
    }

    public function testToolsResourceAloneKeepsTheJobToolsAsToday(): void
    {
        self::assertSame(self::JOB_TOOLS, $this->listNames(['Magebit_Mcp::tools'], asyncCapable: true));

        $this->jobs->method('getByJobId')->willReturn($this->job(self::OTHER_TOOL));
        $response = $this->call(
            'system_job_get',
            ['Magebit_Mcp::tools', self::OTHER_RESOURCE],
            asyncCapable: true
        );
        self::assertNull($response->error);
    }

    /**
     * @param list<string> $allowed
     * @param bool $asyncCapable
     * @return list<string>
     */
    private function listNames(array $allowed, bool $asyncCapable): array
    {
        $registry = $this->registry($asyncCapable);
        $acl = $this->acl($allowed);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitizeForClient')->willReturnArgument(1);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('isDisabled')->willReturn(false);
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);

        $handler = new ToolsListHandler(
            $registry,
            $acl,
            $config,
            $sanitizer,
            $this->createMock(LoggerInterface::class),
            new ToolDomain(['system' => 'System', 'tax' => 'Tax', 'catalog' => 'Catalog']),
            $disabled,
            null,
            null,
            null,
            new JobToolGate($registry, $acl)
        );
        $response = $handler->handle(new Request(1, false, 'tools/list', []), $this->context());

        self::assertNull($response->error);
        self::assertIsArray($response->result);
        $tools = $response->result['tools'];
        self::assertIsArray($tools);
        $names = [];
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name']);
            $names[] = $tool['name'];
        }
        return $names;
    }

    /**
     * @param string $wireName
     * @param list<string> $allowed
     * @param bool $asyncCapable
     * @return Response
     */
    private function call(string $wireName, array $allowed, bool $asyncCapable): Response
    {
        $registry = $this->registry($asyncCapable);
        $acl = $this->acl($allowed);
        $coercer = $this->createMock(ArgumentCoercer::class);
        $coercer->method('coerce')->willReturnArgument(1);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturnArgument(1);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('isDisabled')->willReturn(false);
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);

        $handler = new ToolsCallHandler(
            $registry,
            $acl,
            $this->createMock(JsonSchemaValidator::class),
            $this->createMock(RateLimiterInterface::class),
            $this->createMock(EventManager::class),
            $config,
            new AuditContext(),
            $this->contextProvider,
            $this->createMock(LoggerInterface::class),
            $coercer,
            $sanitizer,
            $disabled,
            null,
            null,
            new JobToolGate($registry, $acl)
        );

        return $handler->handle(
            new Request(7, false, 'tools/call', ['name' => $wireName, 'arguments' => ['job_id' => 'j1']]),
            $this->context()
        );
    }

    /**
     * Real `system.job.get`, a plain `system.job.list`, the async-capable (or sync-only) bulk tool
     * {@see ASYNC_TOOL} whose resource sits outside `Magebit_Mcp::tools`, and {@see OTHER_TOOL}.
     *
     * @param bool $asyncCapable
     * @return ToolRegistryInterface
     */
    private function registry(bool $asyncCapable): ToolRegistryInterface
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturnCallback(fn (): array => $this->tools);
        $registry->method('has')->willReturnCallback(fn (string $n): bool => isset($this->tools[$n]));
        $registry->method('get')->willReturnCallback(fn (string $n): ToolInterface => $this->tools[$n]);
        $registry->method('getCanonicalName')->willReturnCallback(
            function (string $n): ?string {
                foreach (array_keys($this->tools) as $name) {
                    if (str_replace('.', '_', $name) === $n || $name === $n) {
                        return $name;
                    }
                }
                return null;
            }
        );

        $this->tools['system.job.get'] = new Get(
            $this->contextProvider,
            new JobToolAccess($this->jobs, $registry, $this->aclFromContext(), $this->contextProvider),
            $this->jobs
        );
        $list = $this->createMock(ToolInterface::class);
        $list->method('getName')->willReturn('system.job.list');
        $list->method('getTitle')->willReturn('List Background Jobs');
        $list->method('getDescription')->willReturn('List jobs.');
        $list->method('getAclResource')->willReturn('Magebit_Mcp::tools');
        $list->method('getWriteMode')->willReturn(WriteMode::READ);
        $list->method('getInputSchema')->willReturn(['type' => 'object']);
        $this->tools['system.job.list'] = $list;
        $this->tools[self::ASYNC_TOOL] = $this->bulk(self::ASYNC_TOOL, self::ASYNC_RESOURCE, $asyncCapable);
        $this->tools[self::OTHER_TOOL] = $this->bulk(self::OTHER_TOOL, self::OTHER_RESOURCE, true);

        return $registry;
    }

    /**
     * @param string $name
     * @param string $resource
     * @param bool $asyncCapable
     * @return BulkToolAdapter
     */
    private function bulk(string $name, string $resource, bool $asyncCapable): BulkToolAdapter
    {
        $bulk = $this->createMock(BulkToolAdapter::class);
        $bulk->method('getName')->willReturn($name);
        $bulk->method('getTitle')->willReturn('Bulk');
        $bulk->method('getDescription')->willReturn('Bulk tool.');
        $bulk->method('getAclResource')->willReturn($resource);
        $bulk->method('getUnderlyingAclResource')->willReturn($resource);
        $bulk->method('getWriteMode')->willReturn(WriteMode::READ);
        $bulk->method('getInputSchema')->willReturn(['type' => 'object']);
        $bulk->method('isAsyncCapable')->willReturn($asyncCapable);
        return $bulk;
    }

    /** @var list<string> */
    private array $allowed = [];

    /**
     * @param list<string> $allowed
     * @return AclChecker
     */
    private function acl(array $allowed): AclChecker
    {
        $this->allowed = $allowed;
        return $this->aclFromContext();
    }

    /**
     * ACL mock reading the resources of the current test's role, shared by the handlers and the job tool.
     *
     * @return AclChecker
     */
    private function aclFromContext(): AclChecker
    {
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnCallback(
            fn (User $_u, string $resource): bool => in_array($resource, $this->allowed, true)
        );
        return $acl;
    }

    /**
     * Wildcard-grant token for admin 5 with writes allowed.
     *
     * @return AuthenticatedContext
     */
    private function context(): AuthenticatedContext
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $token->method('getAllowWrites')->willReturn(true);
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(5);
        return new AuthenticatedContext($token, $admin);
    }

    /**
     * @param string $toolName
     * @return JobInterface
     */
    private function job(string $toolName): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn('j1');
        $job->method('getToolName')->willReturn($toolName);
        $job->method('getAdminUserId')->willReturn(5);
        $job->method('getState')->willReturn(JobInterface::STATE_COMPLETED);
        return $job;
    }
}
