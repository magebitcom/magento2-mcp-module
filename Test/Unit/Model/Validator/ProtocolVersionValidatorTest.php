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
    /**
     * @var ProtocolVersionValidator
     */
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
        // HTTP layer or dev proxies (e.g. MCP Inspector) may fold two identical
        // `Mcp-Protocol-Version` headers into one comma-separated value.
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
}
