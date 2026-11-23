<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Console\Command\TokenDeleteCommand;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Review M2: deleting a token cancels its jobs first, while the job rows still point at it.
 */
class TokenDeleteCommandTest extends TestCase
{
    public function testCliDeleteCancelsJobsBeforeTheRowGoes(): void
    {
        $calls = [];
        $token = $this->token(null);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->with(9)->willReturn($token);
        $repo->expects(self::once())->method('deleteById')->with(9)
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'delete';
            });
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->with($token)
            ->willReturnCallback(function () use (&$calls): int {
                $calls[] = 'cancel';
                return 3;
            });

        $tester = new CommandTester(new TokenDeleteCommand($repo, $canceller));
        $tester->execute(['id' => '9']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['cancel', 'delete'], $calls);
        self::assertStringContainsString('Token 9 deleted.', $tester->getDisplay());
        self::assertStringContainsString('3 background job(s) were cancelled.', $tester->getDisplay());
    }

    public function testRevokedTokenDeleteDoesNotCancelJobs(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($this->token('2026-11-01 00:00:00'));
        $repo->expects(self::once())->method('deleteById')->with(9);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');

        $tester = new CommandTester(new TokenDeleteCommand($repo, $canceller));
        $tester->execute(['id' => '9']);

        self::assertStringNotContainsString('background job', $tester->getDisplay());
    }

    public function testNotFoundCancelsNothing(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willThrowException(NoSuchEntityException::singleField('id', 9));
        $repo->expects(self::never())->method('deleteById');
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');

        $tester = new CommandTester(new TokenDeleteCommand($repo, $canceller));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token 9 not found.');
        $tester->execute(['id' => '9']);
    }

    private function token(?string $revokedAt): Token
    {
        $token = $this->createMock(Token::class);
        $token->method('getRevokedAt')->willReturn($revokedAt);
        return $token;
    }
}
