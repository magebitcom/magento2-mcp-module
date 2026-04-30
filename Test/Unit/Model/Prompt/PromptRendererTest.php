<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Prompt;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Model\Prompt\PromptRenderer;
use PHPUnit\Framework\TestCase;

class PromptRendererTest extends TestCase
{
    public function testRendersWithoutPlaceholdersWhenPromptHasNoArguments(): void
    {
        $renderer = new PromptRenderer();
        $messages = $renderer->render(
            $this->makePrompt(arguments: [], messages: [
                new PromptMessage(PromptMessage::ROLE_USER, 'no placeholders here'),
            ]),
            []
        );

        self::assertCount(1, $messages);
        self::assertSame('no placeholders here', $messages[0]->text);
    }

    public function testSubstitutesDeclaredArgument(): void
    {
        $renderer = new PromptRenderer();
        $prompt = $this->makePrompt(
            arguments: [new PromptArgument('query', 'desc', true)],
            messages: [
                new PromptMessage(PromptMessage::ROLE_USER, 'looking for: "{{query}}"'),
            ]
        );

        $messages = $renderer->render($prompt, ['query' => 'support email']);

        self::assertSame('looking for: "support email"', $messages[0]->text);
    }

    public function testSubstitutesMissingOptionalArgumentToEmptyString(): void
    {
        $renderer = new PromptRenderer();
        $prompt = $this->makePrompt(
            arguments: [new PromptArgument('hint', 'desc', false)],
            messages: [
                new PromptMessage(PromptMessage::ROLE_USER, 'hint=[{{hint}}]'),
            ]
        );

        $messages = $renderer->render($prompt, []);
        self::assertSame('hint=[]', $messages[0]->text);
    }

    public function testRendersMultiplePlaceholdersAndMessages(): void
    {
        $renderer = new PromptRenderer();
        $prompt = $this->makePrompt(
            arguments: [
                new PromptArgument('a', 'a', false),
                new PromptArgument('b', 'b', false),
            ],
            messages: [
                new PromptMessage(PromptMessage::ROLE_USER, '{{a}} then {{b}}'),
                new PromptMessage(PromptMessage::ROLE_ASSISTANT, 'b then a: {{b}} {{a}}'),
            ]
        );

        $messages = $renderer->render($prompt, ['a' => 'first', 'b' => 'second']);

        self::assertSame('first then second', $messages[0]->text);
        self::assertSame('b then a: second first', $messages[1]->text);
        self::assertSame(PromptMessage::ROLE_ASSISTANT, $messages[1]->role);
    }

    /**
     * @param array<int, PromptArgument> $arguments
     * @param array<int, PromptMessage> $messages
     */
    private function makePrompt(array $arguments, array $messages): PromptInterface
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getArguments')->willReturn($arguments);
        $prompt->method('getMessages')->willReturn($messages);
        return $prompt;
    }
}
