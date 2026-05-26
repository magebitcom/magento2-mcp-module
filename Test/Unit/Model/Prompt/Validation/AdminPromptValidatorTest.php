<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Prompt\Validation;

use InvalidArgumentException;
use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\Prompt\PromptRegistry;
use Magebit\Mcp\Model\Prompt\Validation\AdminPromptValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminPromptValidatorTest extends TestCase
{
    /** @var PromptRegistry&MockObject */
    private PromptRegistry $promptRegistry;

    private AdminPromptValidator $validator;

    protected function setUp(): void
    {
        $this->promptRegistry = $this->createMock(PromptRegistry::class);
        $this->promptRegistry->method('getStaticNames')->willReturn(['system.health_check']);
        $this->validator = new AdminPromptValidator($this->promptRegistry);
    }

    public function testValidPromptPasses(): void
    {
        $prompt = $this->makePrompt(
            name: 'custom.greet',
            title: 'Greet someone',
            description: 'Say hi',
            body: 'Hello, {{name}}!',
            arguments: [['name' => 'name', 'description' => 'Recipient', 'required' => true]],
        );
        $this->expectNotToPerformAssertions();
        $this->validator->validate($prompt);
    }

    public function testRejectsEmptyName(): void
    {
        $prompt = $this->makePrompt(name: '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pick a prompt name');
        $this->validator->validate($prompt);
    }

    public function testRejectsNameWithoutCustomPrefix(): void
    {
        $prompt = $this->makePrompt(name: 'system.something');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "custom."');
        $this->validator->validate($prompt);
    }

    public function testRejectsNameWithEmptySuffix(): void
    {
        $prompt = $this->makePrompt(name: 'custom.');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Add a slug');
        $this->validator->validate($prompt);
    }

    public function testRejectsNameWithBadCharacters(): void
    {
        $prompt = $this->makePrompt(name: 'custom.Greet');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lowercase letters');
        $this->validator->validate($prompt);
    }

    public function testRejectsNameThatCollidesWithDiPrompt(): void
    {
        $this->promptRegistry = $this->createMock(PromptRegistry::class);
        $this->promptRegistry->method('getStaticNames')->willReturn(['custom.greet']);
        $this->validator = new AdminPromptValidator($this->promptRegistry);

        $prompt = $this->makePrompt(
            name: 'custom.greet',
            body: 'Hello, {{name}}!',
            arguments: [['name' => 'name', 'description' => '', 'required' => true]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->validator->validate($prompt);
    }

    public function testRejectsEmptyTitle(): void
    {
        $prompt = $this->makePrompt(title: '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('short title');
        $this->validator->validate($prompt);
    }

    public function testRejectsEmptyBody(): void
    {
        $prompt = $this->makePrompt(body: '');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Write the prompt body');
        $this->validator->validate($prompt);
    }

    public function testRejectsBodyOverSizeCap(): void
    {
        $prompt = $this->makePrompt(body: str_repeat('a', AdminPromptValidator::MAX_BODY_BYTES + 1));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too long');
        $this->validator->validate($prompt);
    }

    public function testRejectsPlaceholderWithoutDeclaredArgument(): void
    {
        $prompt = $this->makePrompt(
            body: 'Hello, {{customer_name}}!',
            arguments: [['name' => 'name', 'description' => '', 'required' => true]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{{customer_name}}');
        $this->validator->validate($prompt);
    }

    public function testRejectsDeclaredArgumentNotReferencedInBody(): void
    {
        $prompt = $this->makePrompt(
            body: 'Hello, world!',
            arguments: [['name' => 'unused', 'description' => '', 'required' => false]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Argument "unused"');
        $this->validator->validate($prompt);
    }

    public function testRejectsDuplicateArgumentName(): void
    {
        $prompt = $this->makePrompt(
            body: 'Hello, {{name}}!',
            arguments: [
                ['name' => 'name', 'description' => '', 'required' => true],
                ['name' => 'name', 'description' => '', 'required' => false],
            ],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declared twice');
        $this->validator->validate($prompt);
    }

    public function testRejectsInvalidArgumentName(): void
    {
        $prompt = $this->makePrompt(
            body: 'Hello, {{Name}}!',
            arguments: [['name' => 'Name', 'description' => '', 'required' => true]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lowercase letters, numbers, and underscores');
        $this->validator->validate($prompt);
    }

    /**
     * @param string $name
     * @param string $title
     * @param string $description
     * @param string $body
     * @param array<int, array{name: string, description: string, required: bool}> $arguments
     */
    private function makePrompt(
        string $name = 'custom.greet',
        string $title = 'Greet someone',
        string $description = 'Say hi',
        string $body = 'Hello, {{name}}!',
        array $arguments = [['name' => 'name', 'description' => 'Recipient', 'required' => true]]
    ): AdminPrompt {
        $prompt = $this->createMock(AdminPrompt::class);
        $prompt->method('getName')->willReturn($name);
        $prompt->method('getTitle')->willReturn($title);
        $prompt->method('getDescription')->willReturn($description);
        $prompt->method('getBody')->willReturn($body);
        $prompt->method('getArguments')->willReturn($arguments);
        return $prompt;
    }
}
