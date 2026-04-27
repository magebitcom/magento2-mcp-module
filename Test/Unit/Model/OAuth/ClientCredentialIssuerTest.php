<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use InvalidArgumentException;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientFactory;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientCredentialIssuerTest extends TestCase
{
    /**
     * @phpstan-var ClientFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientFactory&MockObject $factory;

    /**
     * @phpstan-var ClientRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientRepository&MockObject $repo;

    /**
     * @phpstan-var TokenGenerator&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenGenerator&MockObject $generator;

    /**
     * @phpstan-var TokenHasher&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenHasher&MockObject $hasher;

    private ClientCredentialIssuer $issuer;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(ClientFactory::class);
        $this->repo = $this->createMock(ClientRepository::class);
        $this->generator = $this->createMock(TokenGenerator::class);
        $this->hasher = $this->createMock(TokenHasher::class);

        $this->issuer = new ClientCredentialIssuer(
            $this->factory,
            $this->repo,
            $this->generator,
            $this->hasher
        );
    }

    public function testIssueGeneratesUuidV4ClientIdAndHashesSecret(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('setName')->with('mycli');
        $client->expects(self::once())->method('setClientId')->with(self::callback(
            static fn ($id) => is_string($id) && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id
            ) === 1
        ));
        $client->expects(self::once())->method('setClientSecretHash')->with('hashed-secret');
        $client->expects(self::once())->method('setRedirectUris')->with(['https://example/cb']);

        $this->factory->method('create')->willReturn($client);
        $this->repo->expects(self::once())->method('save')->with($client)->willReturn($client);
        $this->generator->method('generate')->willReturn('plaintext-secret-32-byte-hex');
        $this->hasher->method('hash')->with('plaintext-secret-32-byte-hex')->willReturn('hashed-secret');

        $result = $this->issuer->issue('mycli', ['https://example/cb']);

        self::assertSame($client, $result['client']);
        self::assertSame('plaintext-secret-32-byte-hex', $result['client_secret']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['client_id']
        );
    }

    public function testIssueRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issue('', ['https://example.com/cb']);
    }

    public function testIssueRejectsWhitespaceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issue('   ', ['https://example.com/cb']);
    }

    public function testIssueRejectsEmptyRedirectUriList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issue('test', []);
    }

    public function testIssueRejectsHttpNonLocalhostRedirect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issue('test', ['http://example.com/cb']);
    }

    public function testIssueAcceptsHttpLocalhost(): void
    {
        $this->stubHappyPath();

        $result = $this->issuer->issue('test', ['http://localhost:3000/cb']);
        self::assertArrayHasKey('client_id', $result);
    }

    public function testIssueAcceptsHttp127001(): void
    {
        $this->stubHappyPath();

        $result = $this->issuer->issue('test', ['http://127.0.0.1:8080/cb']);
        self::assertArrayHasKey('client_id', $result);
    }

    public function testIssueRejectsMalformedUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issuer->issue('test', ['not-a-url']);
    }

    /**
     * Wire mocks for a successful issue() call (used by happy-path assertions
     * that don't care about the exact field values).
     */
    private function stubHappyPath(): void
    {
        $client = $this->createMock(Client::class);
        $this->factory->method('create')->willReturn($client);
        $this->repo->method('save')->willReturn($client);
        $this->generator->method('generate')->willReturn('plaintext-secret');
        $this->hasher->method('hash')->willReturn('hashed-secret');
    }
}
