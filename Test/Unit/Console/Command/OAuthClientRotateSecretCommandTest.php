<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Console\Command\OAuthClientRotateSecretCommand;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OAuthClientRotateSecretCommandTest extends TestCase
{
    /**
     * @phpstan-var ClientRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientRepository&MockObject $clientRepository;

    /**
     * @phpstan-var ClientCredentialIssuer&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientCredentialIssuer&MockObject $issuer;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->issuer = $this->createMock(ClientCredentialIssuer::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);
    }

    public function testPrintsTheNewSecretOnce(): void
    {
        $client = $this->stubClient(5);
        $this->issuer->expects(self::once())->method('rotateSecret')->with($client)->willReturn('new-secret');

        $tester = $this->runCommand(['id' => '5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('new-secret', $tester->getDisplay());
        self::assertStringContainsString('will not be shown again', $tester->getDisplay());
    }

    public function testLiveTokensSurviveRotationByDefault(): void
    {
        $this->stubClient(5);
        $this->issuer->method('rotateSecret')->willReturn('new-secret');
        $this->tokenRepository->expects(self::never())->method('revokeAllForClient');

        $this->runCommand(['id' => '5']);
    }

    public function testRevokeTokensAlsoInvalidatesIssuedTokens(): void
    {
        $this->stubClient(5);
        $this->issuer->method('rotateSecret')->willReturn('new-secret');
        $this->tokenRepository->expects(self::once())->method('revokeAllForClient')->with(5)->willReturn(3);

        $tester = $this->runCommand(['id' => '5', '--revoke-tokens' => true]);

        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testFailedRevocationStillPrintsTheNewSecret(): void
    {
        // Rotation already committed — swallowing the secret would strand the operator.
        $this->stubClient(5);
        $this->issuer->method('rotateSecret')->willReturn('new-secret');
        $this->tokenRepository->method('revokeAllForClient')
            ->willThrowException(new \RuntimeException('db gone'));

        $tester = $this->runCommand(['id' => '5', '--revoke-tokens' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('new-secret', $tester->getDisplay());
        self::assertStringContainsString('db gone', $tester->getDisplay());
    }

    public function testUnknownClientIsReported(): void
    {
        $this->clientRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('id', 9));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/9/');

        $this->runCommand(['id' => '9']);
    }

    /**
     * @param int $id
     * @return Client&MockObject
     */
    private function stubClient(int $id): Client&MockObject
    {
        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn($id);
        $client->method('getName')->willReturn('Claude Web');
        $client->method('getClientId')->willReturn('uuid-1');
        $this->clientRepository->method('getById')->with($id)->willReturn($client);
        return $client;
    }

    /**
     * @param array<string, mixed> $input
     * @return CommandTester
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(new OAuthClientRotateSecretCommand(
            $this->clientRepository,
            $this->issuer,
            $this->tokenRepository
        ));
        $tester->execute($input);
        return $tester;
    }
}
