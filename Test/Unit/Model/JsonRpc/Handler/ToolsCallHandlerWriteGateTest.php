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
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Validator\ArgumentCoercer;
use Magebit\Mcp\Model\Validator\JsonSchemaValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ToolsCallHandlerWriteGateTest extends TestCase
{
    public function testStoreSettingOffNamesTheStoreSetting(): void
    {
        $response = $this->call(false, true, WriteMode::WRITE);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::WRITE_NOT_ALLOWED, $response->error->code);
        $this->assertStringContainsString('switched off for this store', $response->error->message);
        $this->assertStringContainsString('magebit_mcp/general/allow_writes', $response->error->message);
        $this->assertSame(['reason' => 'store_setting'], $response->error->data);
    }

    public function testTokenWithoutWritesNamesTheToken(): void
    {
        $response = $this->call(true, false, WriteMode::WRITE);

        $this->assertNotNull($response->error);
        $this->assertSame(ErrorCode::WRITE_NOT_ALLOWED, $response->error->code);
        $this->assertStringContainsString('issued without write access', $response->error->message);
        $this->assertStringNotContainsString('magebit_mcp/general/allow_writes', $response->error->message);
        $this->assertSame(['reason' => 'token'], $response->error->data);
    }

    public function testBothLayersOffReportsTheStoreSettingFirst(): void
    {
        $response = $this->call(false, false, WriteMode::WRITE);

        $this->assertNotNull($response->error);
        $this->assertSame(['reason' => 'store_setting'], $response->error->data);
    }

    public function testWriteToolRunsWhenBothLayersAreOpen(): void
    {
        $response = $this->call(true, true, WriteMode::WRITE);

        $this->assertNull($response->error);
    }

    public function testReadToolIgnoresTheWriteLayers(): void
    {
        $response = $this->call(false, false, WriteMode::READ);

        $this->assertNull($response->error);
    }

    /**
     * @param bool $storeAllowsWrites
     * @param bool $tokenAllowsWrites
     * @param WriteMode $mode
     * @return Response
     */
    private function call(bool $storeAllowsWrites, bool $tokenAllowsWrites, WriteMode $mode): Response
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('catalog.product.stock.set');
        $tool->method('getAclResource')->willReturn('Magebit_McpCatalogTools::tool_catalog_product_stock_set');
        $tool->method('getWriteMode')->willReturn($mode);
        $tool->method('getInputSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willReturn(ToolResult::text('ok'));

        $toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $toolRegistry->method('getCanonicalName')->willReturn('catalog.product.stock.set');
        $toolRegistry->method('get')->willReturn($tool);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $token->method('getAllowWrites')->willReturn($tokenAllowsWrites);

        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(1);

        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn(true);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($storeAllowsWrites);

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
            $config,
            new AuditContext(),
            new AuthenticatedContextProvider(),
            $this->createMock(LoggerInterface::class),
            $coercer,
            $sanitizer,
            $disabledTools
        );

        return $handler->handle(
            new Request(1, false, 'tools/call', ['name' => 'catalog_product_stock_set', 'arguments' => []]),
            new AuthenticatedContext($token, $adminUser)
        );
    }
}
