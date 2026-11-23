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
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\JsonRpc\StructuredOutputPolicy;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToolsListHandlerTest extends TestCase
{
    public function testEmittedToolNamesUseUnderscoresInsteadOfDots(): void
    {
        // Claude.ai's frontend validates MCP tool names against
        // `^[a-zA-Z0-9_-]{1,64}$` and rejects dots. The wire form must
        // therefore replace dots with underscores; the canonical dotted
        // identity stays internal (registry, ACL, audit, scopes).
        $tool = $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list');
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $response = $handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        );

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame(['system_store_list'], $names);
    }

    public function testEmittedNamesPreserveUnderscoresWithinSegments(): void
    {
        // marketing.catalog_rule.set_active → marketing_catalog_rule_set_active
        $tool = $this->makeTool(
            'marketing.catalog_rule.set_active',
            'Magebit_McpMarketingTools::tool_marketing_catalog_rule_set_active'
        );
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame(['marketing_catalog_rule_set_active'], $names);
    }

    /**
     * MCP spec 2025-06-18 §6.5 lets servers attach an `annotations` object to
     * each tool — `title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`,
     * `openWorldHint`. Clients (Claude Desktop, Anthropic SDK callers) use
     * `destructiveHint` to decide whether to prompt the operator before firing
     * the tool. The README's safety story rests on these annotations being
     * advertised, so a read-only tool must emit `readOnlyHint:true` /
     * `destructiveHint:false`.
     */
    public function testReadToolAdvertisesReadOnlyAnnotations(): void
    {
        $tool = $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list');
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $annotations = $this->extractToolAnnotations($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertCount(1, $annotations);
        self::assertSame('System: System.store.list', $annotations[0]['title']);
        self::assertTrue($annotations[0]['readOnlyHint']);
        self::assertFalse($annotations[0]['destructiveHint']);
    }

    public function testOutputSchemaOnlyOnBulkTools(): void
    {
        $bulk = $this->createMock(BulkToolAdapter::class);
        $this->stubToolBasics($bulk, 'catalog.product.bulk_update', 'Magebit_Mcp::tool_bulk');
        $bulk->method('getOutputSchema')->willReturn(['type' => 'object', 'properties' => ['summary' => []]]);
        $plain = $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list');

        $current = $this->listTools(
            $this->makeHandler([$bulk, $plain], true, null, true, protocolVersion: '2025-06-18')
        );
        self::assertSame(
            ['type' => 'object', 'properties' => ['summary' => []]],
            $current[0]['outputSchema']
        );
        self::assertArrayNotHasKey('outputSchema', $current[1]);

        foreach ([null, '2025-03-26'] as $old) {
            $legacy = $this->listTools(
                $this->makeHandler([$bulk, $plain], true, null, true, protocolVersion: $old)
            );
            self::assertArrayNotHasKey('outputSchema', $legacy[0]);
            self::assertArrayNotHasKey('outputSchema', $legacy[1]);
        }
    }

    /**
     * @param ToolsListHandler $handler
     * @return list<array<string, mixed>>
     */
    private function listTools(ToolsListHandler $handler): array
    {
        $response = $handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        );
        self::assertNotNull($response->result);
        /** @var list<array<string, mixed>> $tools */
        $tools = $response->result['tools'];
        return $tools;
    }

    public function testDisplayTitleIsPrefixedWithDomainLabel(): void
    {
        // Acronym domains use the label map ("cms" → "CMS", not "Cms"), applied
        // to both the top-level title and annotations.title.
        $tool = $this->makeTool('cms.page.get', 'Magebit_McpCmsTools::tool_cms_page_get');
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $response = $handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        );

        self::assertNotNull($response->result);
        $tools = $response->result['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertIsArray($tools[0]);
        self::assertSame('CMS: Cms.page.get', $tools[0]['title']);
        self::assertIsArray($tools[0]['annotations']);
        self::assertSame('CMS: Cms.page.get', $tools[0]['annotations']['title']);
    }

    public function testWriteToolWithoutConfirmationAdvertisesNonDestructiveAnnotations(): void
    {
        $tool = $this->makeTool(
            'catalog.product.create',
            'Magebit_McpCatalogTools::tool_catalog_product_create',
            WriteMode::WRITE,
            confirmationRequired: false
        );
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $annotations = $this->extractToolAnnotations($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertCount(1, $annotations);
        self::assertFalse($annotations[0]['readOnlyHint']);
        self::assertFalse($annotations[0]['destructiveHint']);
    }

    public function testDestructiveWriteToolAdvertisesDestructiveHint(): void
    {
        $tool = $this->makeTool(
            'catalog.product.delete',
            'Magebit_McpCatalogTools::tool_catalog_product_delete',
            WriteMode::WRITE,
            confirmationRequired: true
        );
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $annotations = $this->extractToolAnnotations($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertCount(1, $annotations);
        self::assertFalse($annotations[0]['readOnlyHint']);
        self::assertTrue($annotations[0]['destructiveHint']);
    }

    public function testTokenScopeStillCheckedAgainstCanonicalName(): void
    {
        // Scopes are stored as canonical (dotted) names on tokens. Wire-form
        // emission must not break scope filtering — a token scoped to
        // ['system.store.list'] should still see that tool listed even though
        // the emitted name is the underscored variant.
        $tool = $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list');
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: ['system.store.list'], aclAllow: true);

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(['system.store.list'], true), $this->createMock(User::class))
        ));

        self::assertSame(['system_store_list'], $names);
    }

    public function testDisabledToolIsOmittedFromList(): void
    {
        // A tool the admin disabled must be invisible to the client, exactly as
        // if it were never registered.
        $enabled = $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list');
        $disabled = $this->makeTool('cms.page.get', 'Magebit_McpCmsTools::tool_cms_page_get');
        $handler = $this->makeHandler(
            [$enabled, $disabled],
            allowWrites: true,
            scopes: null,
            aclAllow: true,
            disabled: ['cms.page.get']
        );

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame(['system_store_list'], $names);
    }

    public function testToolIsOmittedWhenItsUnderlyingMagentoAclIsDenied(): void
    {
        // The role holds the MCP tool resource but not the Magento resource the
        // tool wraps, so tools/call would refuse it. Advertising it costs the
        // client a wasted call and an unexplained -32004.
        $tool = $this->makeUnderlyingAclTool(
            'inventory.source_item.set',
            'Magebit_McpInventoryTools::tool_inventory_source_item_set',
            'Magento_InventoryApi::stock_source_item_assign'
        );
        $handler = $this->makeHandler(
            [$tool],
            allowWrites: true,
            scopes: null,
            aclAllow: true,
            denied: ['Magento_InventoryApi::stock_source_item_assign']
        );

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame([], $names);
    }

    public function testToolIsListedWhenItsUnderlyingMagentoAclIsGranted(): void
    {
        $tool = $this->makeUnderlyingAclTool(
            'inventory.source_item.set',
            'Magebit_McpInventoryTools::tool_inventory_source_item_set',
            'Magento_InventoryApi::stock_source_item_assign'
        );
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame(['inventory_source_item_set'], $names);
    }

    public function testToolDeclaringNoUnderlyingResourceIsListedOnItsOwnAcl(): void
    {
        // Returning null means "no second gate" — the tool must not be filtered.
        $tool = $this->makeUnderlyingAclTool(
            'system.store.list',
            'Magebit_Mcp::tool_system_store_list',
            null
        );
        $handler = $this->makeHandler([$tool], allowWrites: true, scopes: null, aclAllow: true);

        $names = $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken(null, true), $this->createMock(User::class))
        ));

        self::assertSame(['system_store_list'], $names);
    }

    public function testJobToolsListedForLiteralGrantWithAsyncTool(): void
    {
        $names = $this->listNamesForJobFixture(asyncCapable: true, scopes: ['catalog.product.bulk_update']);

        self::assertSame(['catalog_product_bulk_update', 'system_job_get', 'system_job_cancel'], $names);
    }

    public function testJobToolsHiddenWithoutAsyncTool(): void
    {
        $names = $this->listNamesForJobFixture(
            asyncCapable: false,
            scopes: ['catalog.product.bulk_update', 'system.store.list']
        );

        self::assertSame(['catalog_product_bulk_update', 'system_store_list'], $names);
    }

    public function testJobToolsStillNeedTheRoleAclAndTheWriteGate(): void
    {
        $names = $this->listNamesForJobFixture(
            asyncCapable: true,
            scopes: ['catalog.product.bulk_update'],
            allowWrites: false
        );
        // cancel is a WRITE tool: hidden while writes are off, even though the scope check passes.
        self::assertSame(['catalog_product_bulk_update', 'system_job_get'], $names);

        // The async bulk tool's own resource admits the role to the job tools without Magebit_Mcp::tools.
        $names = $this->listNamesForJobFixture(
            asyncCapable: true,
            scopes: ['catalog.product.bulk_update'],
            denied: ['Magebit_Mcp::tools']
        );
        self::assertSame(['catalog_product_bulk_update', 'system_job_get', 'system_job_cancel'], $names);

        $names = $this->listNamesForJobFixture(
            asyncCapable: true,
            scopes: ['catalog.product.bulk_update', 'system.store.list'],
            denied: ['Magebit_Mcp::tools', 'Magebit_Mcp::tool_bulk']
        );
        self::assertSame(['system_store_list'], $names);
    }

    /**
     * @phpstan-param list<string> $scopes
     * @phpstan-param list<string> $denied
     * @phpstan-return list<string>
     */
    private function listNamesForJobFixture(
        bool $asyncCapable,
        array $scopes,
        bool $allowWrites = true,
        array $denied = []
    ): array {
        $bulk = $this->createMock(BulkToolAdapter::class);
        $this->stubToolBasics($bulk, 'catalog.product.bulk_update', 'Magebit_Mcp::tool_bulk');
        $bulk->method('isAsyncCapable')->willReturn($asyncCapable);
        $tools = [
            $bulk,
            $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list'),
            $this->makeTool('system.job.get', 'Magebit_Mcp::tools'),
            $this->makeTool('system.job.cancel', 'Magebit_Mcp::tools', WriteMode::WRITE, true),
        ];
        $handler = $this->makeHandler($tools, $allowWrites, $scopes, true, denied: $denied);

        return $this->extractToolNames($handler->handle(
            new Request(1, false, 'tools/list', []),
            new AuthenticatedContext($this->makeToken($scopes, $allowWrites), $this->createMock(User::class))
        ));
    }

    /**
     * Extracts the emitted tool names from a tools/list response in a way
     * PHPStan can narrow — the Response::result type is array<string, mixed>.
     *
     * @phpstan-return list<string>
     */
    private function extractToolNames(\Magebit\Mcp\Model\JsonRpc\Response $response): array
    {
        self::assertNotNull($response->result);
        $tools = $response->result['tools'] ?? null;
        self::assertIsArray($tools);
        $names = [];
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertArrayHasKey('name', $tool);
            self::assertIsString($tool['name']);
            $names[] = $tool['name'];
        }
        return $names;
    }

    /**
     * @phpstan-param list<ToolInterface> $tools
     * @phpstan-param list<string>|null $scopes
     * @phpstan-param list<string> $disabled
     * @phpstan-param list<string> $denied
     */
    private function makeHandler(
        array $tools,
        bool $allowWrites,
        ?array $scopes,
        bool $aclAllow,
        array $disabled = [],
        array $denied = [],
        ?string $protocolVersion = null
    ): ToolsListHandler {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($byName);
        $registry->method('has')->willReturnCallback(static fn (string $name): bool => isset($byName[$name]));
        $registry->method('get')->willReturnCallback(static fn (string $name): ToolInterface => $byName[$name]);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturnCallback(
            static fn (User $_user, string $resource): bool => $aclAllow && !in_array($resource, $denied, true)
        );

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($allowWrites);

        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturnCallback(
            static fn (string $_n, array $schema): array => $schema
        );

        $logger = $this->createMock(LoggerInterface::class);
        $toolDomain = new ToolDomain(['system' => 'System', 'cms' => 'CMS', 'catalog' => 'Catalog']);

        $disabledTools = $this->createMock(DisabledTools::class);
        $disabledTools->method('isDisabled')->willReturnCallback(
            static fn (string $name): bool => in_array($name, $disabled, true)
        );

        $auditContext = new AuditContext();
        $auditContext->protocolVersion = $protocolVersion;

        return new ToolsListHandler(
            $registry,
            $aclChecker,
            $config,
            $sanitizer,
            $logger,
            $toolDomain,
            $disabledTools,
            null,
            $auditContext,
            new StructuredOutputPolicy(),
            new JobToolGate($registry, $aclChecker)
        );
    }

    private function makeUnderlyingAclTool(
        string $name,
        string $aclResource,
        ?string $underlyingResource
    ): ToolInterface {
        $tool = $this->createMock(UnderlyingAclToolFixtureInterface::class);
        $this->stubToolBasics($tool, $name, $aclResource);
        $tool->method('getUnderlyingAclResource')->willReturn($underlyingResource);
        return $tool;
    }

    private function makeTool(
        string $name,
        string $aclResource,
        WriteMode $writeMode = WriteMode::READ,
        bool $confirmationRequired = false
    ): ToolInterface {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getTitle')->willReturn(ucfirst($name));
        $tool->method('getDescription')->willReturn('desc');
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $tool->method('getAclResource')->willReturn($aclResource);
        $tool->method('getWriteMode')->willReturn($writeMode);
        $tool->method('getConfirmationRequired')->willReturn($confirmationRequired);
        return $tool;
    }

    /**
     * @param ToolInterface&MockObject $tool
     * @param string $name
     * @param string $aclResource
     * @return void
     */
    private function stubToolBasics($tool, string $name, string $aclResource): void
    {
        $tool->method('getName')->willReturn($name);
        $tool->method('getTitle')->willReturn(ucfirst($name));
        $tool->method('getDescription')->willReturn('desc');
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $tool->method('getAclResource')->willReturn($aclResource);
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        $tool->method('getConfirmationRequired')->willReturn(false);
    }

    /**
     * @phpstan-return list<array<string, mixed>>
     */
    private function extractToolAnnotations(\Magebit\Mcp\Model\JsonRpc\Response $response): array
    {
        self::assertNotNull($response->result);
        $tools = $response->result['tools'] ?? null;
        self::assertIsArray($tools);
        $annotations = [];
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertArrayHasKey('annotations', $tool);
            self::assertIsArray($tool['annotations']);
            $annotations[] = $tool['annotations'];
        }
        return $annotations;
    }

    /**
     * @phpstan-param list<string>|null $scopes
     */
    private function makeToken(?array $scopes, bool $allowWrites): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn($scopes);
        $token->method('getAllowWrites')->willReturn($allowWrites);
        return $token;
    }
}

/**
 * PHPUnit 9 cannot mock an intersection of types, so the fixture names the combination.
 */
interface UnderlyingAclToolFixtureInterface extends ToolInterface, UnderlyingAclAwareInterface
{
}
