<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Prompt;

use InvalidArgumentException;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Model\Prompt\AdminPromptProvider;
use Magebit\Mcp\Model\Prompt\PromptRegistry;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PromptRegistryTest extends TestCase
{
    /** @var AdminPromptProvider&MockObject */
    private AdminPromptProvider $adminPromptProvider;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->adminPromptProvider = $this->createMock(AdminPromptProvider::class);
        $this->adminPromptProvider->method('getAll')->willReturn([]);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testEmptyRegistryConstructsAndExposesEmptyList(): void
    {
        $registry = $this->makeRegistry();
        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('anything'));
    }

    public function testGetReturnsRegisteredPrompt(): void
    {
        $prompt = $this->makePrompt('system.health_check');
        $registry = $this->makeRegistry(['system.health_check' => $prompt]);
        self::assertTrue($registry->has('system.health_check'));
        self::assertSame($prompt, $registry->get('system.health_check'));
    }

    public function testGetThrowsWhenPromptIsMissing(): void
    {
        $registry = $this->makeRegistry();
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
        $this->makeRegistry(['system.bogus' => new \stdClass()]);
    }

    public function testRejectsKeyMismatch(): void
    {
        $prompt = $this->makePrompt('system.health_check');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match getName()');
        $this->makeRegistry(['system.something_else' => $prompt]);
    }

    public function testRejectsInvalidName(): void
    {
        $prompt = $this->makePrompt('NoDot');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is invalid');
        $this->makeRegistry(['NoDot' => $prompt]);
    }

    public function testAdminProviderRowsAreMergedIntoTheRegistry(): void
    {
        $static = $this->makePrompt('system.health_check');
        $admin = $this->makePrompt('custom.greet');
        $this->adminPromptProvider = $this->createMock(AdminPromptProvider::class);
        $this->adminPromptProvider->method('getAll')->willReturn(['custom.greet' => $admin]);

        $registry = $this->makeRegistry(['system.health_check' => $static]);

        self::assertSame(['system.health_check', 'custom.greet'], array_keys($registry->all()));
        self::assertSame($admin, $registry->get('custom.greet'));
        self::assertTrue($registry->has('custom.greet'));
    }

    public function testDiPromptWinsOnCollisionAndLogsWarning(): void
    {
        $static = $this->makePrompt('system.health_check');
        $admin = $this->makePrompt('system.health_check');
        $this->adminPromptProvider = $this->createMock(AdminPromptProvider::class);
        $this->adminPromptProvider->method('getAll')->willReturn(['system.health_check' => $admin]);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('shadows a DI-registered prompt'), self::isType('array'));

        $registry = $this->makeRegistry(['system.health_check' => $static]);
        self::assertSame($static, $registry->get('system.health_check'));
    }

    public function testProviderFailureFallsBackToDiOnly(): void
    {
        $static = $this->makePrompt('system.health_check');
        $this->adminPromptProvider = $this->createMock(AdminPromptProvider::class);
        $this->adminPromptProvider->method('getAll')
            ->willThrowException(new RuntimeException('db down'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Admin prompt provider unavailable'), self::isType('array'));

        $registry = $this->makeRegistry(['system.health_check' => $static]);
        self::assertSame(['system.health_check'], array_keys($registry->all()));
    }

    public function testGetStaticNamesReturnsDiKeysOnly(): void
    {
        $static = $this->makePrompt('system.health_check');
        $admin = $this->makePrompt('custom.greet');
        $this->adminPromptProvider = $this->createMock(AdminPromptProvider::class);
        $this->adminPromptProvider->method('getAll')->willReturn(['custom.greet' => $admin]);

        $registry = $this->makeRegistry(['system.health_check' => $static]);
        self::assertSame(['system.health_check'], $registry->getStaticNames());
    }

    /**
     * @param array<string, PromptInterface> $prompts
     */
    private function makeRegistry(array $prompts = []): PromptRegistry
    {
        return new PromptRegistry($this->adminPromptProvider, $this->logger, $prompts);
    }

    private function makePrompt(string $name): PromptInterface
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn($name);
        return $prompt;
    }
}
