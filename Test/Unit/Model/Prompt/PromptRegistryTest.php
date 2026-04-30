<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Prompt;

use InvalidArgumentException;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Model\Prompt\PromptRegistry;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class PromptRegistryTest extends TestCase
{
    public function testEmptyRegistryConstructsAndExposesEmptyList(): void
    {
        $registry = new PromptRegistry();
        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('anything'));
    }

    public function testGetReturnsRegisteredPrompt(): void
    {
        $prompt = $this->makePrompt('system.health_check');
        $registry = new PromptRegistry(['system.health_check' => $prompt]);
        self::assertTrue($registry->has('system.health_check'));
        self::assertSame($prompt, $registry->get('system.health_check'));
    }

    public function testGetThrowsWhenPromptIsMissing(): void
    {
        $registry = new PromptRegistry();
        $this->expectException(NoSuchEntityException::class);
        $registry->get('system.nope');
    }

    public function testRejectsNonPromptInstance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');
        // PHPStan flags this on purpose — wrong type — but the runtime check
        // is the whole point of the test, so we suppress the static error.
        // @phpstan-ignore-next-line
        new PromptRegistry(['system.bogus' => new \stdClass()]);
    }

    public function testRejectsKeyMismatch(): void
    {
        $prompt = $this->makePrompt('system.health_check');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match getName()');
        new PromptRegistry(['system.something_else' => $prompt]);
    }

    public function testRejectsInvalidName(): void
    {
        $prompt = $this->makePrompt('NoDot');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is invalid');
        new PromptRegistry(['NoDot' => $prompt]);
    }

    private function makePrompt(string $name): PromptInterface
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn($name);
        return $prompt;
    }
}
