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
use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\Prompt\AdminPromptAdapter;
use PHPUnit\Framework\TestCase;

class AdminPromptAdapterTest extends TestCase
{
    public function testForwardsScalarAccessorsFromModel(): void
    {
        $model = $this->createMock(AdminPrompt::class);
        $model->method('getName')->willReturn('custom.greet');
        $model->method('getTitle')->willReturn('Greet someone');
        $model->method('getDescription')->willReturn('Send a hello.');
        $model->method('getRequiresWrite')->willReturn(true);
        $model->method('getBody')->willReturn('Hello, {{name}}!');
        $model->method('getArguments')->willReturn([]);

        $adapter = new AdminPromptAdapter($model);

        self::assertSame('custom.greet', $adapter->getName());
        self::assertSame('Greet someone', $adapter->getTitle());
        self::assertSame('Send a hello.', $adapter->getDescription());
        self::assertTrue($adapter->getRequiresWrite());
    }

    public function testGetMessagesWrapsBodyAsSingleUserMessage(): void
    {
        $model = $this->createMock(AdminPrompt::class);
        $model->method('getBody')->willReturn('Hello, {{name}}!');
        $model->method('getArguments')->willReturn([]);

        $adapter = new AdminPromptAdapter($model);
        $messages = $adapter->getMessages();

        self::assertCount(1, $messages);
        self::assertInstanceOf(PromptMessage::class, $messages[0]);
        self::assertSame(PromptMessage::ROLE_USER, $messages[0]->role);
        self::assertSame('Hello, {{name}}!', $messages[0]->text);
    }

    public function testGetArgumentsMapsToPromptArgumentValueObjects(): void
    {
        $model = $this->createMock(AdminPrompt::class);
        $model->method('getArguments')->willReturn([
            ['name' => 'first', 'description' => 'The first one', 'required' => true],
            ['name' => 'second', 'description' => 'Optional one', 'required' => false],
        ]);

        $adapter = new AdminPromptAdapter($model);
        $arguments = $adapter->getArguments();

        self::assertCount(2, $arguments);
        self::assertInstanceOf(PromptArgument::class, $arguments[0]);
        self::assertSame('first', $arguments[0]->name);
        self::assertSame('The first one', $arguments[0]->description);
        self::assertTrue($arguments[0]->required);
        self::assertSame('second', $arguments[1]->name);
        self::assertFalse($arguments[1]->required);
    }
}
