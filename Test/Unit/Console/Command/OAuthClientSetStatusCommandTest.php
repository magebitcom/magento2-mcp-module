<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Console\Command\OAuthClientSetStatusCommand;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OAuthClientSetStatusCommandTest extends TestCase
{
    /**
     * @phpstan-var ClientRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientRepository&MockObject $clientRepository;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);
    }

    public function testDisablingAlsoRevokesLiveTokens(): void
    {
        // Matches the admin UI: without revocation the toggle only blocks new
        // grants while already-issued access tokens keep working.
        $client = $this->stubClient(5, disabled: false);
        $client->expects(self::once())->method('setDisabled')->with(true);
        $this->clientRepository->expects(self::once())->method('save')->with($client)->willReturn($client);
        $this->tokenRepository->expects(self::once())->method('revokeAllForClient')->with(5)->willReturn(4);

        $tester = $this->runCommand(['id' => '5', '--disabled' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('4', $tester->getDisplay());
    }

    public function testEnablingDoesNotTouchTokens(): void
    {
        $client = $this->stubClient(5, disabled: true);
        $client->expects(self::once())->method('setDisabled')->with(false);
        $this->clientRepository->expects(self::once())->method('save')->with($client)->willReturn($client);
        $this->tokenRepository->expects(self::never())->method('revokeAllForClient');

        $tester = $this->runCommand(['id' => '5', '--enabled' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('enabled', $tester->getDisplay());
    }

    public function testDisablingAnAlreadyDisabledClientSkipsRevocation(): void
    {
        $this->stubClient(5, disabled: true);
        $this->tokenRepository->expects(self::never())->method('revokeAllForClient');

        $tester = $this->runCommand(['id' => '5', '--disabled' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already', $tester->getDisplay());
    }

    public function testFailedRevocationIsReportedAsAFailure(): void
    {
        $client = $this->stubClient(5, disabled: false);
        $client->method('setDisabled');
        $this->clientRepository->method('save')->willReturn($client);
        $this->tokenRepository->method('revokeAllForClient')
            ->willThrowException(new \RuntimeException('db gone'));

        $tester = $this->runCommand(['id' => '5', '--disabled' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('db gone', $tester->getDisplay());
    }

    public function testExactlyOneStatusFlagIsRequired(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/--disabled|--enabled/');

        $this->runCommand(['id' => '5']);
    }

    public function testBothStatusFlagsAtOnceAreRejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->runCommand(['id' => '5', '--disabled' => true, '--enabled' => true]);
    }

    public function testUnknownClientIsReported(): void
    {
        $this->clientRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('id', 9));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/9/');

        $this->runCommand(['id' => '9', '--disabled' => true]);
    }

    /**
     * @param int $id
     * @param bool $disabled
     * @return Client&MockObject
     */
    private function stubClient(int $id, bool $disabled): Client&MockObject
    {
        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn($id);
        $client->method('getName')->willReturn('Claude Web');
        $client->method('getClientId')->willReturn('uuid-1');
        $client->method('isDisabled')->willReturn($disabled);
        $this->clientRepository->method('getById')->with($id)->willReturn($client);
        return $client;
    }

    /**
     * @param array<string, mixed> $input
     * @return CommandTester
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(
            new OAuthClientSetStatusCommand($this->clientRepository, $this->tokenRepository)
        );
        $tester->execute($input);
        return $tester;
    }
}
