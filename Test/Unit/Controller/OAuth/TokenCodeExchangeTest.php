<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Controller\OAuth\Token;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Http\CorsResponder;
use Magebit\Mcp\Model\OAuth\AccessTokenIssuer;
use Magebit\Mcp\Model\OAuth\AuthCode;
use Magebit\Mcp\Model\OAuth\AuthCodeRepository;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\IssuedTokenPair;
use Magebit\Mcp\Model\OAuth\OAuthErrorResponse;
use Magebit\Mcp\Model\OAuth\PkceVerifier;
use Magebit\Mcp\Model\OAuth\RefreshTokenRotator;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The authorization_code grant copies the auth code's record of what consent offered onto the access token.
 */
class TokenCodeExchangeTest extends TestCase
{
    /**
     * @return void
     */
    public function testCodeExchangeCopiesTheOfferedRecordOntoTheAccessToken(): void
    {
        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::once())->method('issue')
            ->with(7, 'Claude', 42, false, ['sales.order.get'], null, ['sales.order.get', 'cms.page.get'])
            ->willReturn($this->pair());

        $this->controller($issuer, ['sales.order.get'], ['sales.order.get', 'cms.page.get'])->execute();
    }

    /**
     * A code written before the record existed exchanges without one (legacy rule for that token).
     *
     * @return void
     */
    public function testCodeWithoutRecordIssuesWithoutRecord(): void
    {
        $issuer = $this->createMock(AccessTokenIssuer::class);
        $issuer->expects(self::once())->method('issue')
            ->with(7, 'Claude', 42, false, ['sales.order.get'], null, null)
            ->willReturn($this->pair());

        $this->controller($issuer, ['sales.order.get'], null)->execute();
    }

    /**
     * @param AccessTokenIssuer&MockObject $issuer
     * @param array<int, string> $granted
     * @param array<int, string>|null $offered
     * @return Token
     */
    private function controller(AccessTokenIssuer&MockObject $issuer, array $granted, ?array $offered): Token
    {
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'code' => 'the-code',
            'code_verifier' => 'verifier',
            'redirect_uri' => 'https://client.example/cb',
        ];
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeader')->willReturn(false);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name): ?string => $params[$name] ?? null
        );

        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn(7);
        $client->method('getName')->willReturn('Claude');
        $client->method('isDisabled')->willReturn(false);
        $client->method('getClientSecretHash')->willReturn('hash:secret');
        $clients = $this->createMock(ClientRepository::class);
        $clients->method('getByClientId')->with('client-id')->willReturn($client);

        $hasher = $this->createMock(TokenHasher::class);
        $hasher->method('hash')->willReturnCallback(static fn (string $plain): string => 'hash:' . $plain);

        $authCode = $this->createMock(AuthCode::class);
        $authCode->method('getId')->willReturn(5);
        $authCode->method('getOAuthClientId')->willReturn(7);
        $authCode->method('getAdminUserId')->willReturn(42);
        $authCode->method('isValid')->willReturn(true);
        $authCode->method('getRedirectUri')->willReturn('https://client.example/cb');
        $authCode->method('getCodeChallenge')->willReturn('challenge');
        $authCode->method('getCodeChallengeMethod')->willReturn('S256');
        $authCode->method('getScope')->willReturn('mcp:read');
        $authCode->method('getGrantedTools')->willReturn($granted);
        $authCode->method('getOfferedTools')->willReturn($offered);
        $codes = $this->createMock(AuthCodeRepository::class);
        $codes->method('getByHash')->with('hash:the-code')->willReturn($authCode);
        $codes->method('markUsed')->with(5)->willReturn(true);

        $pkce = $this->createMock(PkceVerifier::class);
        $pkce->method('verify')->willReturn(true);

        $resolver = $this->createMock(ToolGrantResolver::class);
        $resolver->method('hasWriteTool')->willReturn(false);

        // The controller turns any throwable into a server_error response; a swallowed one fails here.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        return new Token(
            $request,
            $this->createMock(HttpResponse::class),
            $clients,
            $codes,
            $hasher,
            $pkce,
            $issuer,
            $this->createMock(RefreshTokenRotator::class),
            $this->createMock(OAuthErrorResponse::class),
            $resolver,
            $this->createMock(CorsResponder::class),
            $logger
        );
    }

    /**
     * @return IssuedTokenPair
     */
    private function pair(): IssuedTokenPair
    {
        return new IssuedTokenPair(
            accessToken: 'access',
            accessTokenId: 100,
            expiresIn: 3600,
            refreshToken: 'refresh',
            refreshTokenId: 34,
            grantedScope: 'mcp:read'
        );
    }
}
