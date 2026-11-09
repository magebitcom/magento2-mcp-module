<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Validator;

use Magebit\Mcp\Model\Validator\ProtocolVersionValidator;
use PHPUnit\Framework\TestCase;

class ProtocolVersionValidatorTest extends TestCase
{
    private ProtocolVersionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProtocolVersionValidator();
    }

    public function testAcceptsLatest(): void
    {
        $this->assertTrue($this->validator->isSupported('2025-06-18'));
    }

    public function testAcceptsPriorSupportedRevision(): void
    {
        $this->assertTrue($this->validator->isSupported('2025-03-26'));
    }

    public function testRejectsUnknownVersion(): void
    {
        $this->assertFalse($this->validator->isSupported('1999-01-01'));
    }

    public function testAcceptsFoldedDuplicateHeader(): void
    {
        // Proxies may fold duplicate headers into a comma-separated value.
        $this->assertTrue($this->validator->isSupported('2025-06-18, 2025-06-18'));
    }

    public function testAcceptsMixedFoldedHeaderIfAnySupported(): void
    {
        $this->assertTrue($this->validator->isSupported('1999-01-01, 2025-06-18'));
    }

    public function testRejectsFoldedHeaderWhenAllUnknown(): void
    {
        $this->assertFalse($this->validator->isSupported('1999-01-01, 2000-01-01'));
    }

    public function testNegotiateEchoesSupportedOlderVersion(): void
    {
        $this->assertSame('2025-03-26', $this->validator->negotiate('2025-03-26'));
    }

    public function testNegotiateEchoesLatest(): void
    {
        $this->assertSame('2025-06-18', $this->validator->negotiate('2025-06-18'));
    }

    /**
     * @dataProvider unsupportedRequests
     * @param mixed $requested
     */
    public function testNegotiateFallsBackToLatest(mixed $requested): void
    {
        $this->assertSame(ProtocolVersionValidator::LATEST, $this->validator->negotiate($requested));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unsupportedRequests(): array
    {
        return [
            'unknown' => ['1999-01-01'],
            'missing' => [null],
            'non-string' => [20250326],
            'array' => [['2025-03-26']],
            'folded list' => ['2025-03-26, 2025-06-18'],
            'padded' => [' 2025-03-26'],
        ];
    }
}
