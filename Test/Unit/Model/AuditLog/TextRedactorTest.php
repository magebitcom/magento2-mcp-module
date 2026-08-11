<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\AuditLog;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\Mcp\Model\AuditLog\TextRedactor;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TextRedactorTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private $logger;

    private ?TextRedactor $redactor = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->redactor = new TextRedactor(
            new FingerprintStrategy($deploymentConfig),
            $this->logger,
            ['EMAIL', 'CREDIT_CARD']
        );
    }

    /**
     * @return void
     */
    public function testReplacesAnEmailInsideAFreeFormSqlString(): void
    {
        $out = (string) $this->redactor?->redact("SELECT entity_id FROM customer_entity WHERE email = 'jane@example.com'");

        self::assertStringNotContainsString('jane@example.com', $out);
        self::assertMatchesRegularExpression('/\[EMAIL:[0-9a-f]{16}\]/', $out);
    }

    /**
     * @return void
     */
    public function testPreservesTheSurroundingSqlSoTheTrailStaysReadable(): void
    {
        $out = (string) $this->redactor?->redact("SELECT entity_id FROM customer_entity WHERE email = 'jane@example.com'");

        self::assertStringContainsString('SELECT entity_id FROM customer_entity WHERE email =', $out);
    }

    /**
     * @return void
     */
    public function testLeavesTextWithNoPiiUntouched(): void
    {
        $sql = 'SELECT entity_id, status FROM sales_order LIMIT 100';

        self::assertSame($sql, $this->redactor?->redact($sql));
    }

    /**
     * @return void
     */
    public function testDisabledEntityTypeIsNotRedacted(): void
    {
        // PHONE is not in the configured list, so a bare number stays.
        $out = (string) $this->redactor?->redact("SELECT 1 FROM sales_order WHERE telephone = '+37120000000'");

        self::assertStringContainsString('+37120000000', $out);
    }

    /**
     * @return void
     */
    public function testEmptyStringIsReturnedUnchangedWithoutInvokingTheLibrary(): void
    {
        self::assertSame('', $this->redactor?->redact(''));
    }

    /**
     * @return void
     */
    public function testFailureIsClosedNotOpen(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('unit-test-crypt-key');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        // maxLength 1 forces InputTooLongException on any real input.
        $redactor = new TextRedactor(
            new FingerprintStrategy($deploymentConfig),
            $logger,
            ['EMAIL'],
            1
        );

        $out = $redactor->redact("SELECT 1 WHERE email = 'jane@example.com'");

        self::assertSame('[REDACTION_FAILED]', $out);
        self::assertStringNotContainsString('jane@example.com', $out);
    }
}
