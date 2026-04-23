<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Validator;

use InvalidArgumentException;
use Magebit\Mcp\Model\Validator\OriginValidator;
use PHPUnit\Framework\TestCase;

class OriginValidatorTest extends TestCase
{
    private OriginValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OriginValidator([
            'http://localhost*',
            'https://localhost*',
            'http://127.0.0.1*',
            'https://127.0.0.1*',
        ]);
    }

    public function testAcceptsMissingOrigin(): void
    {
        $this->assertTrue($this->validator->isAllowed(null));
        $this->assertTrue($this->validator->isAllowed(''));
    }

    public function testRejectsOriginLiteralNull(): void
    {
        // Sandboxed iframes and data: URIs send `Origin: null` — precisely the
        // attacker shapes the DNS-rebinding defense is there to catch.
        $this->assertFalse($this->validator->isAllowed('null'));
    }

    public function testAcceptsLoopbackWithoutPort(): void
    {
        $this->assertTrue($this->validator->isAllowed('http://localhost'));
        $this->assertTrue($this->validator->isAllowed('http://127.0.0.1'));
    }

    public function testAcceptsLoopbackWithPort(): void
    {
        $this->assertTrue($this->validator->isAllowed('http://localhost:3000'));
        $this->assertTrue($this->validator->isAllowed('https://localhost:8443'));
        $this->assertTrue($this->validator->isAllowed('http://127.0.0.1:6277'));
    }

    public function testAcceptsLoopbackWithPath(): void
    {
        $this->assertTrue($this->validator->isAllowed('http://localhost/mcp'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function dnsRebindingAttemptProvider(): iterable
    {
        yield 'loopback subdomain' => ['http://localhost.attacker.com'];
        yield 'loopback as label' => ['http://localhost.evil.test'];
        yield 'loopback prefix word' => ['http://localhostess.example.com'];
        yield 'ip label continuation' => ['http://127.0.0.1.attacker.com'];
        yield 'ip with extra octet' => ['http://127.0.0.1234'];
        yield 'schema mismatch' => ['ftp://localhost'];
    }

    /**
     * @dataProvider dnsRebindingAttemptProvider
     */
    public function testRejectsDnsRebindingShapes(string $origin): void
    {
        $this->assertFalse($this->validator->isAllowed($origin));
    }

    public function testRejectsNonListedHost(): void
    {
        $this->assertFalse($this->validator->isAllowed('https://evil.example.com'));
    }

    public function testConstructorThrowsOnEmptyAllowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/allowlist is empty/');

        new OriginValidator([]);
    }
}
