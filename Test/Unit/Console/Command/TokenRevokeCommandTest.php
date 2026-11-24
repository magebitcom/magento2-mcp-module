<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Console\Command\TokenRevokeCommand;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TokenRevokeCommandTest extends TestCase
{
    public function testCliRevokeCancelsJobs(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getRevokedAt')->willReturn('2026-11-20 10:00:00');
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->with(9)->willReturn($this->createMock(Token::class));
        $repo->expects(self::once())->method('revoke')->with(9)->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->with($token)->willReturn(3);

        $tester = new CommandTester(new TokenRevokeCommand($repo, $canceller));
        $tester->execute(['id' => '9']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('3 background job(s) were cancelled.', $tester->getDisplay());
    }

    public function testAlreadyRevokedTokenDoesNotCancelJobs(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getRevokedAt')->willReturn('2026-11-01 00:00:00');
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($token);
        $repo->method('revoke')->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');

        $tester = new CommandTester(new TokenRevokeCommand($repo, $canceller));
        $tester->execute(['id' => '9']);

        self::assertStringNotContainsString('background job', $tester->getDisplay());
    }

    public function testNotFoundDoesNotCancelJobs(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willThrowException(NoSuchEntityException::singleField('id', 9));
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');

        $tester = new CommandTester(new TokenRevokeCommand($repo, $canceller));
        $this->expectException(RuntimeException::class);
        $tester->execute(['id' => '9']);
    }

    public function testOutputUnchangedWhenNothingCancelled(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getRevokedAt')->willReturn(null);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($token);
        $repo->method('revoke')->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->willReturn(0);

        $tester = new CommandTester(new TokenRevokeCommand($repo, $canceller));
        $tester->execute(['id' => '9']);

        self::assertStringNotContainsString('background job', $tester->getDisplay());
    }
}
