<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\PkceVerifier;
use PHPUnit\Framework\TestCase;

class PkceVerifierTest extends TestCase
{
    public function testVerifyMatchesRfcTestVector(): void
    {
        $verifier = new PkceVerifier();
        self::assertTrue($verifier->verify(
            'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'S256'
        ));
    }

    public function testVerifyRejectsMismatchedVerifier(): void
    {
        $verifier = new PkceVerifier();
        // Replace one char in the verifier — challenge no longer matches.
        self::assertFalse($verifier->verify(
            'XBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'S256'
        ));
    }

    public function testVerifyRejectsPlainMethod(): void
    {
        // OAuth 2.1 §7.5.2 forbids plain.
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify(
            str_repeat('A', 43),
            str_repeat('A', 43),
            'plain'
        ));
    }

    public function testVerifyRejectsUnknownMethod(): void
    {
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify(
            str_repeat('A', 43),
            'irrelevant',
            'S512'
        ));
    }

    public function testVerifyRejectsEmptyVerifier(): void
    {
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify('', 'something', 'S256'));
    }

    public function testVerifyRejectsTooShortVerifier(): void
    {
        // RFC 7636 §4.1: minimum 43 chars.
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify(
            str_repeat('A', 42),
            'irrelevant',
            'S256'
        ));
    }

    public function testVerifyRejectsTooLongVerifier(): void
    {
        // RFC 7636 §4.1: maximum 128 chars.
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify(
            str_repeat('A', 129),
            'irrelevant',
            'S256'
        ));
    }

    public function testVerifyRejectsInvalidVerifierCharacters(): void
    {
        // The verifier set is [A-Za-z0-9._~-]. Spaces and slashes are out.
        $verifier = new PkceVerifier();
        self::assertFalse($verifier->verify(
            'dBjftJeZ4CVP-mB92K27uhbUJU1p1r/wW1gFWFOEjXk', // / replaces _
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'S256'
        ));
    }
}
