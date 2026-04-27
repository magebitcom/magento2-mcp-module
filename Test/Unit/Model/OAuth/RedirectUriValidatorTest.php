<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\RedirectUriValidator;
use PHPUnit\Framework\TestCase;

class RedirectUriValidatorTest extends TestCase
{
    public function testExactMatchPasses(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertTrue((new RedirectUriValidator())->isAllowed($client, 'https://example.com/cb'));
    }

    public function testTrailingSlashMismatchFails(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertFalse((new RedirectUriValidator())->isAllowed($client, 'https://example.com/cb/'));
    }

    public function testQueryStringIsStrippedBeforeMatch(): void
    {
        // OAuth allows the AS to preserve query params on redirect, but the base URI must match.
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertTrue((new RedirectUriValidator())->isAllowed($client, 'https://example.com/cb?extra=1'));
    }

    public function testFragmentIsStrippedBeforeMatch(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertTrue((new RedirectUriValidator())->isAllowed($client, 'https://example.com/cb#token=x'));
    }

    public function testHostMismatchFails(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertFalse((new RedirectUriValidator())->isAllowed($client, 'https://attacker.com/cb'));
    }

    public function testSchemeMismatchFails(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn(['https://example.com/cb']);
        self::assertFalse((new RedirectUriValidator())->isAllowed($client, 'http://example.com/cb'));
    }

    public function testMatchesAnyEntryInAllowlist(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn([
            'https://prod.example.com/cb',
            'https://staging.example.com/cb',
        ]);
        self::assertTrue((new RedirectUriValidator())->isAllowed($client, 'https://staging.example.com/cb'));
    }

    public function testEmptyAllowlistRejectsEverything(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getRedirectUris')->willReturn([]);
        self::assertFalse((new RedirectUriValidator())->isAllowed($client, 'https://example.com/cb'));
    }
}
