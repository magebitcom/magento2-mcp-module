<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\RefreshToken;
use Magebit\Mcp\Model\OAuth\RefreshTokenFactory;
use Magebit\Mcp\Model\OAuth\RefreshTokenRepository;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AccessTokenIssuerTest extends TestCase
{
    public function testIssueMintsAccessTokenWithOAuthClientIdAndExpectedFields(): void
    {
        $token = $this->createMock(Token::class);
        $token->expects(self::once())->method('setAdminUserId')->with(42);
        $token->expects(self::once())->method('setName')->with('OAuth: Claude Web');
        $token->expects(self::once())->method('setTokenHash')->with('access-hash');
        $token->expects(self::once())->method('setAllowWrites')->with(false);
        $token->expects(self::once())->method('setExpiresAt')->with(self::callback(
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 3600)) < 5
        ));
        $token->expects(self::once())->method('setOAuthClientId')->with(7);
        $token->method('getId')->willReturn(99);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::once())->method('save')->with($token)->willReturn($token);

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->expects(self::once())->method('setTokenHash')->with('refresh-hash');
        $refresh->expects(self::once())->method('setOAuthClientId')->with(7);
        $refresh->expects(self::once())->method('setAccessTokenId')->with(99);
        $refresh->expects(self::once())->method('setExpiresAt')->with(self::callback(
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 30 * 86400)) < 5
        ));
        $refresh->method('getId')->willReturn(33);

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshFactory->method('create')->willReturn($refresh);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())->method('save')->with($refresh)->willReturn($refresh);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls('access-plain', 'refresh-plain');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturnCallback(static function (string $plain): string {
            return match ($plain) {
                'access-plain' => 'access-hash',
                'refresh-plain' => 'refresh-hash',
                default => 'unexpected',
            };
        });

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config
        );

        $pair = $issuer->issue(7, 'Claude Web', 42, false, 'mcp');

        self::assertSame('access-plain', $pair->accessToken);
        self::assertSame(99, $pair->accessTokenId);
        self::assertSame(3600, $pair->expiresIn);
        self::assertSame('refresh-plain', $pair->refreshToken);
        self::assertSame(33, $pair->refreshTokenId);
    }

    public function testIssueAllowsWritesWhenFlagged(): void
    {
        $token = $this->createMock(Token::class);
        $token->expects(self::once())->method('setAllowWrites')->with(true);
        $token->method('getId')->willReturn(1);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($token);

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(2);

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshFactory->method('create')->willReturn($refresh);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('save')->willReturn($refresh);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls('a', 'r');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config
        );

        $issuer->issue(7, 'Claude Web', 42, true, null);
    }

    public function testIssueRespectsConfiguredLifetimes(): void
    {
        $token = $this->createMock(Token::class);
        $token->expects(self::once())->method('setExpiresAt')->with(self::callback(
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 120)) < 5
        ));
        $token->method('getId')->willReturn(1);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($token);

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->expects(self::once())->method('setExpiresAt')->with(self::callback(
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 7 * 86400)) < 5
        ));
        $refresh->method('getId')->willReturn(2);

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshFactory->method('create')->willReturn($refresh);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('save')->willReturn($refresh);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls('a', 'r');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(120);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(7);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config
        );

        $pair = $issuer->issue(7, 'Claude Web', 42, false, 'mcp');
        self::assertSame(120, $pair->expiresIn);
    }

    public function testIssueThrowsIfAccessTokenSaveReturnsNullId(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(null);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($token);

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturn('p');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OAuth access token row.');

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }

    public function testIssueThrowsIfRefreshTokenSaveReturnsNullId(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(99);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($token);

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(null);

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshFactory->method('create')->willReturn($refresh);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('save')->willReturn($refresh);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls('a', 'r');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OAuth refresh token row.');

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }
}
