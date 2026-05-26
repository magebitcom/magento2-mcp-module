<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Config\Source\ReauthBehavior;
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\RefreshToken;
use Magebit\Mcp\Model\OAuth\RefreshTokenFactory;
use Magebit\Mcp\Model\OAuth\RefreshTokenRepository;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
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
        $token->expects(self::once())->method('setScopes')->with(['catalog.product.list']);
        $token->method('getId')->willReturn(99);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::once())->method('save')->with($token)->willReturn($token);

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->expects(self::once())->method('setTokenHash')->with('refresh-hash');
        $refresh->expects(self::once())->method('setOAuthClientId')->with(7);
        $refresh->expects(self::once())->method('setAccessTokenId')->with(99);
        $refresh->expects(self::once())->method('setParentRefreshTokenId')->with(null);
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
        $hasher->method('hash')->willReturnCallback(static fn (string $plain): string => match ($plain) {
            'access-plain' => 'access-hash',
            'refresh-plain' => 'refresh-hash',
            default => 'unexpected',
        });

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);

        $resolver = $this->createMock(ToolGrantResolver::class);
        $resolver->method('summarizeScope')->with(['catalog.product.list'])->willReturn('mcp:read');

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $resolver
        );

        $pair = $issuer->issue(7, 'Claude Web', 42, false, ['catalog.product.list']);

        self::assertSame('access-plain', $pair->accessToken);
        self::assertSame(99, $pair->accessTokenId);
        self::assertSame(3600, $pair->expiresIn);
        self::assertSame('refresh-plain', $pair->refreshToken);
        self::assertSame(33, $pair->refreshTokenId);
        self::assertSame('mcp:read', $pair->grantedScope);
    }

    public function testIssueLeavesGrantedScopeNullForCliTokens(): void
    {
        $token = $this->createMock(Token::class);
        $token->expects(self::once())->method('setScopes')->with(null);
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

        $resolver = $this->createMock(ToolGrantResolver::class);
        $resolver->expects(self::never())->method('summarizeScope');

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $resolver
        );

        $pair = $issuer->issue(7, 'Claude Web', 42, false, null);
        self::assertNull($pair->grantedScope);
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
            $config,
            $this->createMock(ToolGrantResolver::class)
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
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $pair = $issuer->issue(7, 'Claude Web', 42, false, ['system.store.list']);
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
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OAuth access token row.');

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }

    public function testReauthAllowMultipleSkipsTheLookup(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(1);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($token);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($token);
        // Critical assertion: ALLOW_MULTIPLE must never even ask the repo for active tokens.
        $tokenRepo->expects(self::never())->method('getActiveByClientAndAdmin');
        $tokenRepo->expects(self::never())->method('revoke');

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
        $config->method('getOAuthReauthBehavior')->willReturn(ReauthBehavior::ALLOW_MULTIPLE);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }

    public function testReauthRotateRevokesPriorActiveTokensBeforeIssuing(): void
    {
        $priorA = $this->createMock(Token::class);
        $priorA->method('getId')->willReturn(101);
        $priorB = $this->createMock(Token::class);
        $priorB->method('getId')->willReturn(102);

        $newToken = $this->createMock(Token::class);
        $newToken->method('getId')->willReturn(1);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($newToken);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($newToken);
        $tokenRepo->expects(self::once())
            ->method('getActiveByClientAndAdmin')
            ->with(7, 42)
            ->willReturn([$priorA, $priorB]);
        $revokedIds = [];
        $tokenRepo->expects(self::exactly(2))
            ->method('revoke')
            ->willReturnCallback(function (int $id) use (&$revokedIds, $priorA, $priorB) {
                $revokedIds[] = $id;
                return $id === 101 ? $priorA : $priorB;
            });

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
        $config->method('getOAuthReauthBehavior')->willReturn(ReauthBehavior::ROTATE);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $issuer->issue(7, 'Claude Web', 42, false, null);
        self::assertSame([101, 102], $revokedIds);
    }

    public function testReauthRejectThrowsInvalidGrantWhenActiveTokenExists(): void
    {
        $prior = $this->createMock(Token::class);
        $prior->method('getId')->willReturn(101);

        $newToken = $this->createMock(Token::class);
        $newToken->method('getId')->willReturn(1);

        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($newToken);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::once())
            ->method('getActiveByClientAndAdmin')
            ->with(7, 42)
            ->willReturn([$prior]);
        // Critical: we must NOT have started writing rows before the policy fired.
        $tokenRepo->expects(self::never())->method('save');
        $tokenRepo->expects(self::never())->method('revoke');

        $refreshFactory = $this->createMock(RefreshTokenFactory::class);
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::never())->method('save');

        $generator = $this->createMock(TokenGenerator::class);
        $hasher = $this->createMock(TokenHasher::class);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAccessTokenLifetime')->willReturn(3600);
        $config->method('getOAuthRefreshTokenLifetimeDays')->willReturn(30);
        $config->method('getOAuthReauthBehavior')->willReturn(ReauthBehavior::REJECT);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        try {
            $issuer->issue(7, 'Claude Web', 42, false, null);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
        }
    }

    public function testReauthRejectIssuesNormallyWhenNoActiveTokens(): void
    {
        $newToken = $this->createMock(Token::class);
        $newToken->method('getId')->willReturn(1);
        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($newToken);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('getActiveByClientAndAdmin')->willReturn([]);
        $tokenRepo->expects(self::once())->method('save');

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
        $config->method('getOAuthReauthBehavior')->willReturn(ReauthBehavior::REJECT);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }

    public function testReauthPolicySkippedDuringRefreshTokenRotation(): void
    {
        $newToken = $this->createMock(Token::class);
        $newToken->method('getId')->willReturn(1);
        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturn($newToken);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('save')->willReturn($newToken);
        // When parentRefreshTokenId is set, the policy must not run.
        $tokenRepo->expects(self::never())->method('getActiveByClientAndAdmin');
        $tokenRepo->expects(self::never())->method('revoke');

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
        // Even though policy is REJECT, the rotation path bypasses the check.
        $config->method('getOAuthReauthBehavior')->willReturn(ReauthBehavior::REJECT);

        $issuer = new AccessTokenIssuer(
            $tokenFactory,
            $tokenRepo,
            $refreshFactory,
            $refreshRepo,
            $generator,
            $hasher,
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $issuer->issue(7, 'Claude Web', 42, false, null, parentRefreshTokenId: 55);
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
            $config,
            $this->createMock(ToolGrantResolver::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist OAuth refresh token row.');

        $issuer->issue(7, 'Claude Web', 42, false, null);
    }
}
