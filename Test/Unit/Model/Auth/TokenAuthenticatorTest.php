<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Auth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\UnauthorizedException;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenAuthenticator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TokenAuthenticatorTest extends TestCase
{
    /**
     * @phpstan-var TokenHasher&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenHasher&MockObject $tokenHasher;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $adminUserLookup;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private TokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenHasher = $this->createMock(TokenHasher::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);
        $this->adminUserLookup = $this->createMock(AdminUserLookup::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authenticator = new TokenAuthenticator(
            $this->tokenHasher,
            $this->tokenRepository,
            $this->adminUserLookup,
            $this->logger
        );
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function malformedHeaderProvider(): iterable
    {
        yield 'null header' => [null];
        yield 'empty string' => [''];
        yield 'not a bearer scheme' => ['Basic YWRtaW46c2VjcmV0'];
        yield 'bearer without token' => ['Bearer '];
        yield 'bearer with only whitespace' => ['Bearer    '];
    }

    /**
     * @dataProvider malformedHeaderProvider
     */
    public function testRejectsMalformedHeader(?string $header): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/Invalid bearer token/');

        $this->authenticator->authenticate($header);
    }

    public function testRejectsUnknownHash(): void
    {
        $this->tokenHasher->method('hash')->willReturn('hash-of-plaintext');
        $this->tokenRepository->method('getByHash')
            ->willThrowException(NoSuchEntityException::singleField('bearer', '<redacted>'));

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/Invalid bearer token/');

        $this->authenticator->authenticate('Bearer deadbeef');
    }

    public function testRejectsRevokedToken(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(true);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/Invalid bearer token/');

        $this->authenticator->authenticate('Bearer abc');
    }

    public function testRejectsExpiredToken(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(true);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/Invalid bearer token/');

        $this->authenticator->authenticate('Bearer abc');
    }

    public function testRejectsInactiveAdmin(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(false);
        $token->method('getAdminUserId')->willReturn(42);
        $token->method('getId')->willReturn(7);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $user = $this->createMock(User::class);
        $user->method('getIsActive')->willReturn(0);
        $this->adminUserLookup->method('getById')->willReturn($user);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessageMatches('/Invalid bearer token/');

        $this->authenticator->authenticate('Bearer abc');
    }

    public function testRejectsDeletedAdmin(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(false);
        $token->method('getAdminUserId')->willReturn(42);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $this->adminUserLookup->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('user_id', 42));

        $this->expectException(UnauthorizedException::class);

        $this->authenticator->authenticate('Bearer abc');
    }

    public function testHappyPathReturnsContextAndTouchesLastUsed(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(false);
        $token->method('getAdminUserId')->willReturn(42);
        $token->method('getId')->willReturn(7);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $user = $this->createMock(User::class);
        $user->method('getIsActive')->willReturn(1);
        $this->adminUserLookup->method('getById')->willReturn($user);

        $this->tokenRepository->expects($this->once())
            ->method('touchLastUsed')
            ->with(7);

        $ctx = $this->authenticator->authenticate('Bearer abc');

        $this->assertSame($token, $ctx->token);
        $this->assertSame($user, $ctx->adminUser);
    }

    public function testTouchLastUsedFailureDoesNotFailAuth(): void
    {
        $this->tokenHasher->method('hash')->willReturn('h');
        $token = $this->createMock(Token::class);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(false);
        $token->method('getAdminUserId')->willReturn(42);
        $token->method('getId')->willReturn(7);
        $this->tokenRepository->method('getByHash')->willReturn($token);

        $user = $this->createMock(User::class);
        $user->method('getIsActive')->willReturn(1);
        $this->adminUserLookup->method('getById')->willReturn($user);

        $this->tokenRepository->method('touchLastUsed')
            ->willThrowException(new RuntimeException('DB write down'));
        $this->logger->expects($this->once())->method('warning');

        $ctx = $this->authenticator->authenticate('Bearer abc');

        $this->assertSame($token, $ctx->token);
    }
}
