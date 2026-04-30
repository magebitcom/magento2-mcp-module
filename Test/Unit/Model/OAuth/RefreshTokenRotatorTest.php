<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Exception\OAuthException;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\IssuedTokenPair;
use Magebit\Mcp\Model\OAuth\RefreshToken;
use Magebit\Mcp\Model\OAuth\RefreshTokenRepository;
use Magebit\Mcp\Model\OAuth\RefreshTokenRotator;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class RefreshTokenRotatorTest extends TestCase
{
    public function testRotateRejectsUnknownRefreshToken(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->with('presented')->willReturn('h');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->with('h')
            ->willThrowException(NoSuchEntityException::singleField('token_hash', '<redacted>'));

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $this->createMock(TokenRepository::class),
            $this->createMock(ClientRepository::class),
            $this->createMock(AccessTokenIssuer::class),
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame(400, $e->httpStatus);
            self::assertSame('Refresh token not recognized.', $e->getMessage());
        }
    }

    public function testRotateRejectsRefreshFromDifferentClient(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getOAuthClientId')->willReturn(99);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($refresh);
        $refreshRepo->expects(self::never())->method('revoke');

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::never())->method('revoke');

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $this->createMock(ClientRepository::class),
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame('Refresh token does not belong to this client.', $e->getMessage());
        }
    }

    public function testRotateRejectsExpiredToken(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(33);
        $refresh->method('getOAuthClientId')->willReturn(7);
        $refresh->method('isExpired')->willReturn(true);
        $refresh->method('isRevoked')->willReturn(false);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($refresh);
        $refreshRepo->expects(self::never())->method('revoke');

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::never())->method('revoke');

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $this->createMock(ClientRepository::class),
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame('Refresh token is expired.', $e->getMessage());
        }
    }

    public function testRotateOnReusedRevokedTokenWalksTheChainAndThrows(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $reused = $this->createMock(RefreshToken::class);
        $reused->method('getId')->willReturn(33);
        $reused->method('getOAuthClientId')->willReturn(7);
        $reused->method('isExpired')->willReturn(false);
        $reused->method('isRevoked')->willReturn(true);

        $child = $this->createMock(RefreshToken::class);
        $child->method('getId')->willReturn(34);
        $child->method('getAccessTokenId')->willReturn(101);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($reused);
        $refreshRepo->method('getChildren')->willReturnCallback(
            static fn (int $id): array => $id === 33 ? [$child] : []
        );
        $refreshRepo->expects(self::once())->method('revoke')->with(34)->willReturn(true);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->expects(self::once())->method('revoke')->with(101);

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $this->createMock(ClientRepository::class),
            $issuer,
            $logger
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame('Refresh token reuse detected.', $e->getMessage());
        }
    }

    public function testRotateOnLostCasRaceTreatsAsReuseAndThrows(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(33);
        $refresh->method('getOAuthClientId')->willReturn(7);
        $refresh->method('isExpired')->willReturn(false);
        $refresh->method('isRevoked')->willReturn(false);
        $refresh->method('getAccessTokenId')->willReturn(99);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($refresh);
        // Lost the CAS race — revoke returns false, no descendants.
        $refreshRepo->expects(self::once())->method('revoke')->with(33)->willReturn(false);
        $refreshRepo->method('getChildren')->willReturn([]);

        $accessToken = $this->createMock(Token::class);
        $accessToken->method('getId')->willReturn(99);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('getById')->with(99)->willReturn($accessToken);
        $tokenRepo->expects(self::never())->method('revoke');

        $client = $this->createMock(Client::class);
        $clientRepo = $this->createMock(ClientRepository::class);
        $clientRepo->method('getById')->willReturn($client);

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $clientRepo,
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame('Refresh token reuse detected.', $e->getMessage());
        }
    }

    public function testRotateRejectsIfPairedAccessTokenMissing(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(33);
        $refresh->method('getOAuthClientId')->willReturn(7);
        $refresh->method('isExpired')->willReturn(false);
        $refresh->method('isRevoked')->willReturn(false);
        $refresh->method('getAccessTokenId')->willReturn(99);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($refresh);
        $refreshRepo->expects(self::never())->method('revoke');

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('getById')->with(99)
            ->willThrowException(NoSuchEntityException::singleField('id', 99));
        $tokenRepo->expects(self::never())->method('revoke');

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $this->createMock(ClientRepository::class),
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->oauthError);
            self::assertSame('Linked access token row missing.', $e->getMessage());
        }
    }

    public function testRotateRejectsIfClientNoLongerExists(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(33);
        $refresh->method('getOAuthClientId')->willReturn(7);
        $refresh->method('isExpired')->willReturn(false);
        $refresh->method('isRevoked')->willReturn(false);
        $refresh->method('getAccessTokenId')->willReturn(99);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->willReturn($refresh);
        $refreshRepo->expects(self::never())->method('revoke');

        $accessToken = $this->createMock(Token::class);
        $accessToken->method('getId')->willReturn(99);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('getById')->with(99)->willReturn($accessToken);
        $tokenRepo->expects(self::never())->method('revoke');

        $clientRepo = $this->createMock(ClientRepository::class);
        $clientRepo->method('getById')->with(7)
            ->willThrowException(NoSuchEntityException::singleField('id', 7));

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $clientRepo,
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $rotator->rotate('presented', 7);
            self::fail('Expected OAuthException');
        } catch (OAuthException $e) {
            self::assertSame('invalid_client', $e->oauthError);
            self::assertSame(401, $e->httpStatus);
            self::assertSame('Client no longer exists.', $e->getMessage());
        }
    }

    public function testRotateHappyPathRevokesOldAndIssuesNewWithParentLink(): void
    {
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->with('presented')->willReturn('h');

        $refresh = $this->createMock(RefreshToken::class);
        $refresh->method('getId')->willReturn(33);
        $refresh->method('getOAuthClientId')->willReturn(7);
        $refresh->method('isExpired')->willReturn(false);
        $refresh->method('isRevoked')->willReturn(false);
        $refresh->method('getAccessTokenId')->willReturn(99);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('getByHash')->with('h')->willReturn($refresh);
        $refreshRepo->expects(self::once())->method('revoke')->with(33)->willReturn(true);

        $accessToken = $this->createMock(Token::class);
        $accessToken->method('getId')->willReturn(99);
        $accessToken->method('getAdminUserId')->willReturn(42);
        $accessToken->method('getAllowWrites')->willReturn(true);
        $accessToken->method('getScopes')->willReturn(['catalog.product.list']);

        $tokenRepo = $this->createMock(TokenRepository::class);
        $tokenRepo->method('getById')->with(99)->willReturn($accessToken);
        $tokenRepo->expects(self::once())->method('revoke')->with(99)->willReturn($accessToken);

        $client = $this->createMock(Client::class);
        $client->method('getName')->willReturn('Claude');

        $clientRepo = $this->createMock(ClientRepository::class);
        $clientRepo->method('getById')->with(7)->willReturn($client);

        $expectedPair = new IssuedTokenPair(
            accessToken: 'new-access',
            accessTokenId: 100,
            expiresIn: 3600,
            refreshToken: 'new-refresh',
            refreshTokenId: 34,
            grantedScope: 'mcp:read mcp:write'
        );

        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::once())
            ->method('issue')
            ->with(7, 'Claude', 42, true, ['catalog.product.list'], 33)
            ->willReturn($expectedPair);

        $rotator = new RefreshTokenRotator(
            $hasher,
            $refreshRepo,
            $tokenRepo,
            $clientRepo,
            $issuer,
            $this->createMock(LoggerInterface::class)
        );

        $pair = $rotator->rotate('presented', 7);

        self::assertSame($expectedPair, $pair);
    }
}
