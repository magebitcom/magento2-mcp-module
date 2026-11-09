<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Handler\InitializeHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\Validator\ProtocolVersionValidator;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class InitializeHandlerTest extends TestCase
{
    private const ADMIN_TEXT = 'Some tools perform writes; use those only when asked.';

    public function testInstructionsUnchangedWhenWritesAreAllowed(): void
    {
        self::assertSame(self::ADMIN_TEXT, $this->instructions(self::ADMIN_TEXT, true, true));
    }

    public function testNoInstructionsWhenNoneConfiguredAndWritesAllowed(): void
    {
        self::assertNull($this->instructions(null, true, true));
    }

    public function testStoreSettingOffAddsLineNamingTheSetting(): void
    {
        $instructions = (string) $this->instructions(self::ADMIN_TEXT, false, true);

        self::assertStringStartsWith(self::ADMIN_TEXT . "\n\n", $instructions);
        self::assertStringContainsString('Write tools are switched off for this store', $instructions);
        self::assertStringContainsString('magebit_mcp/general/allow_writes', $instructions);
        self::assertStringContainsString('Only read tools are available in this session.', $instructions);
    }

    public function testReadOnlyTokenAddsLineNamingTheToken(): void
    {
        $instructions = (string) $this->instructions(self::ADMIN_TEXT, true, false);

        self::assertStringContainsString('issued without write access', $instructions);
        self::assertStringContainsString('Only read tools are available in this session.', $instructions);
    }

    public function testWritesOffLineStandsAloneWithoutConfiguredInstructions(): void
    {
        $instructions = (string) $this->instructions(null, true, false);

        self::assertStringStartsWith('Write tools are not allowed for this token', $instructions);
    }

    /**
     * @param string|null $configured
     * @param bool $storeAllowsWrites
     * @param bool $tokenAllowsWrites
     * @return string|null
     */
    private function instructions(?string $configured, bool $storeAllowsWrites, bool $tokenAllowsWrites): ?string
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getServerName')->willReturn('magento-mcp');
        $config->method('getServerInstructions')->willReturn($configured);
        $config->method('isAllowWrites')->willReturn($storeAllowsWrites);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getAllowWrites')->willReturn($tokenAllowsWrites);

        $handler = new InitializeHandler(new ProtocolVersionValidator(), $config, '1.0.0');
        $response = $handler->handle(
            new Request(1, false, 'initialize', []),
            new AuthenticatedContext($token, $this->createMock(User::class))
        );

        self::assertNull($response->error);
        self::assertNotNull($response->result);
        $instructions = $response->result['instructions'] ?? null;
        self::assertTrue($instructions === null || is_string($instructions));
        return $instructions;
    }
}
