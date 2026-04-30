<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\JsonRpc\Handler;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Api\PromptRegistryInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Handler\PromptsListHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class PromptsListHandlerTest extends TestCase
{
    public function testReturnsEmptyArrayWhenRegistryEmpty(): void
    {
        $response = $this->makeHandler([], allowWrites: true)->handle(
            $this->request(),
            $this->context(allowWritesOnToken: true)
        );

        self::assertSame([], $this->prompts($response));
    }

    public function testFiltersWriteRequiringPromptWhenGlobalConfigOff(): void
    {
        $readPrompt = $this->makePrompt('system.read_only', requiresWrite: false);
        $writePrompt = $this->makePrompt('system.refresh_after_edit', requiresWrite: true);

        $response = $this->makeHandler([$readPrompt, $writePrompt], allowWrites: false)->handle(
            $this->request(),
            $this->context(allowWritesOnToken: true)
        );

        $names = array_map(
            static function (array $p): string {
                $name = $p['name'] ?? null;
                return is_string($name) ? $name : '';
            },
            $this->prompts($response)
        );
        self::assertSame(['system.read_only'], $names);
    }

    public function testFiltersWriteRequiringPromptWhenTokenOff(): void
    {
        $writePrompt = $this->makePrompt('system.refresh_after_edit', requiresWrite: true);

        $response = $this->makeHandler([$writePrompt], allowWrites: true)->handle(
            $this->request(),
            $this->context(allowWritesOnToken: false)
        );

        self::assertSame([], $this->prompts($response));
    }

    public function testIncludesWritePromptWhenBothFlagsOn(): void
    {
        $writePrompt = $this->makePrompt('system.refresh_after_edit', requiresWrite: true);

        $response = $this->makeHandler([$writePrompt], allowWrites: true)->handle(
            $this->request(),
            $this->context(allowWritesOnToken: true)
        );

        $names = array_map(
            static function (array $p): string {
                $name = $p['name'] ?? null;
                return is_string($name) ? $name : '';
            },
            $this->prompts($response)
        );
        self::assertSame(['system.refresh_after_edit'], $names);
    }

    public function testEmitsArgumentsArray(): void
    {
        $prompt = $this->makePrompt(
            'system.find_setting',
            requiresWrite: false,
            arguments: [new PromptArgument('query', 'help text', true)]
        );

        $response = $this->makeHandler([$prompt], allowWrites: true)->handle(
            $this->request(),
            $this->context(allowWritesOnToken: true)
        );

        $prompts = $this->prompts($response);
        self::assertCount(1, $prompts);
        self::assertSame([
            ['name' => 'query', 'description' => 'help text', 'required' => true],
        ], $prompts[0]['arguments']);
    }

    /**
     * @param array<int, PromptInterface> $prompts
     */
    private function makeHandler(array $prompts, bool $allowWrites): PromptsListHandler
    {
        $byName = [];
        foreach ($prompts as $prompt) {
            $byName[$prompt->getName()] = $prompt;
        }
        $registry = $this->createMock(PromptRegistryInterface::class);
        $registry->method('all')->willReturn($byName);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn($allowWrites);

        return new PromptsListHandler($registry, $config);
    }

    /**
     * @param array<int, PromptArgument> $arguments
     */
    private function makePrompt(string $name, bool $requiresWrite, array $arguments = []): PromptInterface
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn($name);
        $prompt->method('getTitle')->willReturn(ucfirst($name));
        $prompt->method('getDescription')->willReturn('desc');
        $prompt->method('getArguments')->willReturn($arguments);
        $prompt->method('getRequiresWrite')->willReturn($requiresWrite);
        return $prompt;
    }

    private function request(): Request
    {
        return new Request(1, false, 'prompts/list', []);
    }

    private function context(bool $allowWritesOnToken): AuthenticatedContext
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getAllowWrites')->willReturn($allowWritesOnToken);
        return new AuthenticatedContext($token, $this->createMock(User::class));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prompts(\Magebit\Mcp\Model\JsonRpc\Response $response): array
    {
        self::assertNotNull($response->result, 'Expected success response');
        $prompts = $response->result['prompts'] ?? null;
        self::assertIsArray($prompts);
        /** @var array<int, array<string, mixed>> $prompts */
        return $prompts;
    }
}
