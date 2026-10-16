<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Console\Command\OAuthClientDeleteCommand;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OAuthClientDeleteCommandTest extends TestCase
{
    /**
     * @phpstan-var ClientRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientRepository&MockObject $clientRepository;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepository::class);
    }

    /**
     * @return void
     */
    public function testRevokesLiveTokensByDefault(): void
    {
        $this->stubClient(5);
        $this->clientRepository->expects(self::once())->method('deleteById')->with(5, false)->willReturn(2);

        $tester = $this->runCommand(['id' => '5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Access tokens revoked: 2.', $tester->getDisplay());
    }

    /**
     * @return void
     */
    public function testKeepTokensPassesThroughAndWarns(): void
    {
        $this->stubClient(5);
        $this->clientRepository->expects(self::once())->method('deleteById')->with(5, true)->willReturn(0);

        $tester = $this->runCommand(['id' => '5', '--keep-tokens' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('still valid', $tester->getDisplay());
        self::assertStringNotContainsString('Access tokens revoked', $tester->getDisplay());
    }

    /**
     * @return void
     */
    public function testFailedDeleteIsReported(): void
    {
        $this->stubClient(5);
        $this->clientRepository->method('deleteById')->willThrowException(new RuntimeException('db gone'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to delete OAuth client 5/');

        $this->runCommand(['id' => '5']);
    }

    /**
     * @return void
     */
    public function testUnknownClientIsReported(): void
    {
        $this->clientRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('id', 9));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/9/');

        $this->runCommand(['id' => '9']);
    }

    /**
     * @return void
     */
    public function testNonNumericIdIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/positive integer/');

        $this->runCommand(['id' => 'abc']);
    }

    /**
     * @param int $id
     * @return void
     */
    private function stubClient(int $id): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn($id);
        $client->method('getName')->willReturn('Claude Web');
        $client->method('getClientId')->willReturn('uuid-1');
        $this->clientRepository->method('getById')->with($id)->willReturn($client);
    }

    /**
     * @param array<string, mixed> $input
     * @return CommandTester
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(new OAuthClientDeleteCommand($this->clientRepository));
        $tester->execute($input);
        return $tester;
    }
}
