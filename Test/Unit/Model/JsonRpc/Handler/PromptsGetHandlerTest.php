<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Api\PromptRegistryInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\JsonRpc\Handler\PromptsGetHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\Prompt\PromptRenderer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class PromptsGetHandlerTest extends TestCase
{
    public function testRejectsMissingNameParam(): void
    {
        $response = $this->makeHandler([])->handle(
            new Request(1, false, 'prompts/get', []),
            $this->context(true, true)
        );

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::INVALID_PARAMS->value, $response->error->code->value);
    }

    public function testRejectsUnknownPromptName(): void
    {
        $registry = $this->createMock(PromptRegistryInterface::class);
        $registry->method('get')->willThrowException(NoSuchEntityException::singleField('name', 'system.bogus'));

        $handler = new PromptsGetHandler(
            $registry,
            new PromptRenderer(),
            $this->mockConfig(true),
            new AuditContext()
        );

        $response = $handler->handle(
            new Request(1, false, 'prompts/get', ['name' => 'system.bogus']),
            $this->context(true, true)
        );

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::PROMPT_NOT_FOUND->value, $response->error->code->value);
    }

    public function testRejectsWritePromptWhenGateClosed(): void
    {
        $prompt = $this->makePrompt('system.refresh_after_edit', requiresWrite: true);

        $response = $this->makeHandler([$prompt], allowWritesGlobal: false)->handle(
            new Request(1, false, 'prompts/get', ['name' => 'system.refresh_after_edit']),
            $this->context(false, true)
        );

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::WRITE_NOT_ALLOWED->value, $response->error->code->value);
    }

    public function testRejectsMissingRequiredArgument(): void
    {
        $prompt = $this->makePrompt(
            'system.find_setting',
            requiresWrite: false,
            arguments: [new PromptArgument('query', 'desc', true)],
            messages: [new PromptMessage(PromptMessage::ROLE_USER, 'q={{query}}')]
        );

        $response = $this->makeHandler([$prompt])->handle(
            new Request(1, false, 'prompts/get', ['name' => 'system.find_setting', 'arguments' => []]),
            $this->context(true, true)
        );

        self::assertNotNull($response->error);
        self::assertSame(ErrorCode::INVALID_PARAMS->value, $response->error->code->value);
    }

    public function testHappyPathRendersMessagesWithSubstitution(): void
    {
        $prompt = $this->makePrompt(
            'system.find_setting',
            requiresWrite: false,
            description: 'Find a setting',
            arguments: [new PromptArgument('query', 'desc', true)],
            messages: [new PromptMessage(PromptMessage::ROLE_USER, 'looking for: {{query}}')]
        );

        $response = $this->makeHandler([$prompt])->handle(
            new Request(
                1,
                false,
                'prompts/get',
                ['name' => 'system.find_setting', 'arguments' => ['query' => 'support email']]
            ),
            $this->context(true, true)
        );

        self::assertNotNull($response->result);
        self::assertSame('Find a setting', $response->result['description']);
        self::assertSame(
            [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'looking for: support email'],
                ],
            ],
            $response->result['messages']
        );
    }

    public function testStampsAuditPromptName(): void
    {
        $prompt = $this->makePrompt('system.list_stores', requiresWrite: false);
        $auditContext = new AuditContext();

        $registry = $this->createMock(PromptRegistryInterface::class);
        $registry->method('get')->with('system.list_stores')->willReturn($prompt);

        $handler = new PromptsGetHandler(
            $registry,
            new PromptRenderer(),
            $this->mockConfig(true),
            $auditContext
        );

        $handler->handle(
            new Request(1, false, 'prompts/get', ['name' => 'system.list_stores']),
            $this->context(true, true)
        );

        self::assertSame('system.list_stores', $auditContext->promptName);
    }

    /**
     * @param array<int, PromptInterface> $prompts
     */
    private function makeHandler(array $prompts, bool $allowWritesGlobal = true): PromptsGetHandler
    {
        $registry = $this->createMock(PromptRegistryInterface::class);
        $registry->method('get')->willReturnCallback(static function (string $name) use ($prompts): PromptInterface {
            foreach ($prompts as $p) {
                if ($p->getName() === $name) {
                    return $p;
                }
            }
            throw NoSuchEntityException::singleField('name', $name);
        });

        return new PromptsGetHandler(
            $registry,
            new PromptRenderer(),
            $this->mockConfig($allowWritesGlobal),
            new AuditContext()
        );
    }

    private function mockConfig(bool $allowWrites): ModuleConfig
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($allowWrites);
        return $config;
    }

    /**
     * @param array<int, PromptArgument> $arguments
     * @param array<int, PromptMessage> $messages
     */
    private function makePrompt(
        string $name,
        bool $requiresWrite,
        string $description = 'desc',
        array $arguments = [],
        array $messages = []
    ): PromptInterface {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn($name);
        $prompt->method('getTitle')->willReturn(ucfirst($name));
        $prompt->method('getDescription')->willReturn($description);
        $prompt->method('getArguments')->willReturn($arguments);
        $prompt->method('getRequiresWrite')->willReturn($requiresWrite);
        $prompt->method('getMessages')->willReturn($messages);
        return $prompt;
    }

    private function context(bool $tokenAllowsWrites, bool $unused = true): AuthenticatedContext
    {
        unset($unused);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getAllowWrites')->willReturn($tokenAllowsWrites);
        return new AuthenticatedContext($token, $this->createMock(User::class));
    }
}
