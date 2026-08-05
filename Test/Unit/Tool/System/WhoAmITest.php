<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\WhoAmI;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class WhoAmITest extends TestCase
{
    private AuthenticatedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->contextProvider = new AuthenticatedContextProvider();
    }

    public function testMetadataAndSchema(): void
    {
        $tool = new WhoAmI($this->contextProvider, $this->createMock(ModuleConfig::class));

        self::assertSame('system.whoami', $tool->getName());
        self::assertSame('Who Am I', $tool->getTitle());
        self::assertSame('Magebit_Mcp::tool_system_whoami', $tool->getAclResource());
        self::assertSame(WriteMode::READ, $tool->getWriteMode());
        self::assertFalse($tool->getConfirmationRequired());
        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ], $tool->getInputSchema());
    }

    public function testReturnsCurrentIdentityAndEffectiveWritePermission(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getName')->willReturn('Claude Web');
        $token->method('getAllowWrites')->willReturn(true);

        $adminUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $adminUser->method('getId')->willReturn(42);
        $adminUser->method('getUsername')->willReturn('junaid');
        $adminUser->method('getEmail')->willReturn('junaid@example.com');
        $this->contextProvider->set(new AuthenticatedContext($token, $adminUser));

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);

        $result = (new WhoAmI($this->contextProvider, $config))->execute([]);
        $payload = $this->decodeContent($result->getContent());

        self::assertSame(42, $payload['admin_user_id']);
        self::assertSame('junaid', $payload['username']);
        self::assertSame('junaid@example.com', $payload['email']);
        self::assertSame('Claude Web', $payload['token_name']);
        self::assertTrue($payload['allow_writes']);
        self::assertSame([
            'admin_user_id' => 42,
            'allow_writes' => true,
        ], $result->getAuditSummary());
    }

    /**
     * @dataProvider effectiveWritePermissionProvider
     */
    public function testWritePermissionRequiresGlobalAndTokenPermission(
        bool $globalAllowWrites,
        bool $tokenAllowWrites,
        bool $expected
    ): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getName')->willReturn('Read only');
        $token->method('getAllowWrites')->willReturn($tokenAllowWrites);

        $adminUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $adminUser->method('getId')->willReturn(7);
        $adminUser->method('getUsername')->willReturn('operator');
        $adminUser->method('getEmail')->willReturn('operator@example.com');
        $this->contextProvider->set(new AuthenticatedContext($token, $adminUser));

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($globalAllowWrites);

        $result = (new WhoAmI($this->contextProvider, $config))->execute([]);
        $payload = $this->decodeContent($result->getContent());

        self::assertSame($expected, $payload['allow_writes']);
    }

    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function effectiveWritePermissionProvider(): array
    {
        return [
            'global permission disabled' => [false, true, false],
            'token permission disabled' => [true, false, false],
            'both permissions disabled' => [false, false, false],
        ];
    }

    public function testMissingContextFailsClosed(): void
    {
        $tool = new WhoAmI($this->contextProvider, $this->createMock(ModuleConfig::class));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Authenticated context is unavailable.');

        $tool->execute([]);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function decodeContent(array $content): array
    {
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
