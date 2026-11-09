<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Console\Command\TokenCreateCommand;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TokenCreateCommandTest extends TestCase
{
    private const CANONICAL = ['catalog.product.list', 'system.whoami'];

    /** @var list<array<int, string>|null> */
    private array $savedScopes = [];

    public function testWireNamesAreStoredAsDottedNames(): void
    {
        $tester = $this->mint(['-s' => ['catalog_product_list', 'system_whoami']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([['catalog.product.list', 'system.whoami']], $this->savedScopes);
        self::assertStringContainsString('Scoped to tools: catalog.product.list, system.whoami', $tester->getDisplay());
    }

    public function testDottedNamesAreStoredUnchangedAndDuplicatesCollapse(): void
    {
        $this->mint(['-s' => ['catalog.product.list', 'catalog_product_list']]);

        self::assertSame([['catalog.product.list']], $this->savedScopes);
    }

    public function testNoScopeMeansAllTools(): void
    {
        $this->mint([]);

        self::assertSame([null], $this->savedScopes);
    }

    public function testUnknownNameFailsAndMintsNoToken(): void
    {
        try {
            $this->mint(['-s' => ['catalog_product_list', 'nope', 'no.such.tool']]);
            self::fail('Expected the command to fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Unknown tool name(s) for --scope: nope, no.such.tool.', $e->getMessage());
            self::assertStringContainsString('No token was created.', $e->getMessage());
        }
        self::assertSame([], $this->savedScopes);
    }

    /**
     * @param array<string, mixed> $options
     * @return CommandTester
     */
    private function mint(array $options): CommandTester
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('getCanonicalName')->willReturnCallback(
            static function (string $name): ?string {
                foreach (self::CANONICAL as $canonical) {
                    if ($name === $canonical || $name === str_replace('.', '_', $canonical)) {
                        return $canonical;
                    }
                }
                return null;
            }
        );

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(5);
        $lookup = $this->createMock(AdminUserLookup::class);
        $lookup->method('getByUsername')->willReturn($admin);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturn('plaintext-test-token');
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('hash');

        $token = $this->createMock(Token::class);
        $token->method('setScopes')->willReturnCallback(
            function (?array $scopes) use ($token): Token {
                /** @var array<int, string>|null $scopes */
                $this->savedScopes[] = $scopes;
                return $token;
            }
        );
        $factory = $this->getMockBuilder(TokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($token);

        $command = new TokenCreateCommand(
            $lookup,
            $factory,
            $generator,
            $hasher,
            $this->createMock(TokenRepository::class),
            $registry
        );
        $tester = new CommandTester($command);
        $tester->execute(['--admin-user' => 'admin', '--name' => 'test'] + $options);
        return $tester;
    }
}
