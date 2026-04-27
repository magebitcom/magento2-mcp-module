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
use Magebit\Mcp\Model\OAuth\AuthCode;
use Magebit\Mcp\Model\OAuth\AuthCodeFactory;
use Magebit\Mcp\Model\OAuth\AuthCodeIssuer;
use Magebit\Mcp\Model\OAuth\AuthCodeRepository;
use PHPUnit\Framework\TestCase;

class AuthCodeIssuerTest extends TestCase
{
    public function testIssuePersistsHashedCodeAndReturnsPlaintext(): void
    {
        $authCode = $this->createMock(AuthCode::class);
        $authCode->expects(self::once())->method('setCodeHash')->with('hashed');
        $authCode->expects(self::once())->method('setOAuthClientId')->with(7);
        $authCode->expects(self::once())->method('setAdminUserId')->with(42);
        $authCode->expects(self::once())->method('setRedirectUri')->with('https://x/cb');
        $authCode->expects(self::once())->method('setCodeChallenge')->with('challenge-xyz');
        $authCode->expects(self::once())->method('setCodeChallengeMethod')->with('S256');
        $authCode->expects(self::once())->method('setScope')->with('mcp');
        $authCode->expects(self::once())->method('setExpiresAt')->with(self::callback(
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 60)) < 5
        ));

        $factory = $this->createMock(AuthCodeFactory::class);
        $factory->method('create')->willReturn($authCode);

        $repo = $this->createMock(AuthCodeRepository::class);
        $repo->expects(self::once())->method('save')->with($authCode);

        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturn('plaintext-code');

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->with('plaintext-code')->willReturn('hashed');

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAuthCodeLifetime')->willReturn(60);

        $issuer = new AuthCodeIssuer($factory, $repo, $generator, $hasher, $config);
        $code = $issuer->issue(7, 42, 'https://x/cb', 'challenge-xyz', 'S256', 'mcp');

        self::assertSame('plaintext-code', $code);
    }

    public function testIssueAcceptsNullScope(): void
    {
        $authCode = $this->createMock(AuthCode::class);
        $authCode->expects(self::once())->method('setScope')->with(null);

        $factory = $this->createMock(AuthCodeFactory::class);
        $factory->method('create')->willReturn($authCode);
        $repo = $this->createMock(AuthCodeRepository::class);
        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturn('p');
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAuthCodeLifetime')->willReturn(60);

        $issuer = new AuthCodeIssuer($factory, $repo, $generator, $hasher, $config);
        $issuer->issue(1, 1, 'https://x/cb', 'c', 'S256', null);
    }

    public function testIssueRespectsConfiguredLifetime(): void
    {
        $authCode = $this->createMock(AuthCode::class);
        $authCode->expects(self::once())->method('setExpiresAt')->with(self::callback(
            // 300s lifetime → expires_at within 5s of (now + 300)
            static fn ($v) => is_string($v) && abs(strtotime($v . ' UTC') - (time() + 300)) < 5
        ));

        $factory = $this->createMock(AuthCodeFactory::class);
        $factory->method('create')->willReturn($authCode);
        $repo = $this->createMock(AuthCodeRepository::class);
        $generator = $this->createMock(TokenGenerator::class);
        $generator->method('generate')->willReturn('p');
        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturn('h');
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getOAuthAuthCodeLifetime')->willReturn(300);

        $issuer = new AuthCodeIssuer($factory, $repo, $generator, $hasher, $config);
        $issuer->issue(1, 1, 'https://x/cb', 'c', 'S256', 'mcp');
    }
}
