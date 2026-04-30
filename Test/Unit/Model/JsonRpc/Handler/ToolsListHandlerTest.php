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
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\User\Model\User;
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
     */
    private function makeHandler(array $tools, bool $allowWrites, ?array $scopes, bool $aclAllow): ToolsListHandler
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($byName);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn($aclAllow);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($allowWrites);

        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitize')->willReturnCallback(
            static fn (string $_n, array $schema): array => $schema
        );

        $logger = $this->createMock(LoggerInterface::class);

        return new ToolsListHandler($registry, $aclChecker, $config, $sanitizer, $logger);
    }

    private function makeTool(string $name, string $aclResource): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getTitle')->willReturn(ucfirst($name));
        $tool->method('getDescription')->willReturn('desc');
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $tool->method('getAclResource')->willReturn($aclResource);
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        return $tool;
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
