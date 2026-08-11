<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\AuditLog\Strategy;

use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\PiiRedactor\EntityType;
use Magebit\PiiRedactor\PiiMatch;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class FingerprintStrategyTest extends TestCase
{
    private FingerprintStrategy $strategy;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');
        $this->strategy = new FingerprintStrategy($deploymentConfig);
    }

    /**
     * @param string $entityType
     * @return PiiMatch
     */
    private function match(string $entityType): PiiMatch
    {
        return new PiiMatch($entityType, 0, 5, 1.0, 'test-detector');
    }

    /**
     * @return void
     */
    public function testEmitsTypeAndSixteenHexCharacters(): void
    {
        $out = $this->strategy->redact($this->match(EntityType::EMAIL->value), 'jane@example.com');

        self::assertMatchesRegularExpression('/^\[EMAIL:[0-9a-f]{16}\]$/', $out);
    }

    /**
     * @return void
     */
    public function testSameValueYieldsSameFingerprintSoAuditorsCanGroup(): void
    {
        $first = $this->strategy->redact($this->match(EntityType::EMAIL->value), 'jane@example.com');
        $second = $this->strategy->redact($this->match(EntityType::EMAIL->value), 'jane@example.com');

        self::assertSame($first, $second);
    }

    /**
     * @return void
     */
    public function testDifferentValuesYieldDifferentFingerprints(): void
    {
        $jane = $this->strategy->redact($this->match(EntityType::EMAIL->value), 'jane@example.com');
        $john = $this->strategy->redact($this->match(EntityType::EMAIL->value), 'john@example.com');

        self::assertNotSame($jane, $john);
    }

    /**
     * @return void
     */
    public function testOriginalValueNeverAppearsInTheOutput(): void
    {
        $out = $this->strategy->redact($this->match(EntityType::CREDIT_CARD->value), '4111111111111111');

        self::assertStringNotContainsString('4111', $out);
    }
}
