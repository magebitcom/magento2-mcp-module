<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\AuditLog;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\AuditLog\PiiRedactor;
use Magebit\Mcp\Model\AuditLog\Strategy\FingerprintStrategy;
use Magebit\Mcp\Model\AuditLog\TextRedactor;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class PiiRedactorTest extends TestCase
{
    private ?PiiRedactor $redactor = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn('unit-test-crypt-key');
        $logger = $this->createMock(LoggerInterface::class);

        $textRedactor = new TextRedactor(
            new FingerprintStrategy($deploymentConfig),
            $logger,
            ['EMAIL']
        );

        $this->redactor = new PiiRedactor($deploymentConfig, [], $textRedactor);
    }

    /**
     * @return void
     */
    public function testRedactsPiiInsideAFreeFormStringValueWhoseKeyIsNotSensitive(): void
    {
        $out = $this->redactor?->redact([
            'query' => "SELECT entity_id FROM customer_entity WHERE email = 'jane@example.com'",
        ]);

        self::assertIsArray($out);
        self::assertIsString($out['query']);
        self::assertStringNotContainsString('jane@example.com', $out['query']);
        self::assertStringContainsString('FROM customer_entity', $out['query']);
    }

    /**
     * @return void
     */
    public function testSensitiveKeyStillFingerprintsTheWholeValue(): void
    {
        $out = $this->redactor?->redact(['email' => 'jane@example.com']);

        self::assertIsArray($out);
        self::assertIsString($out['email']);
        self::assertStringNotContainsString('jane@example.com', $out['email']);
        self::assertStringNotContainsString('[EMAIL:', $out['email']);
    }

    /**
     * @return void
     */
    public function testNestedStringValuesAreAlsoScanned(): void
    {
        $out = $this->redactor?->redact(['filters' => [['value' => "x jane@example.com y"]]]);

        self::assertIsArray($out);
        self::assertStringNotContainsString('jane@example.com', json_encode($out) ?: '');
    }

    /**
     * Without this wiring the object manager leaves $textRedactor null (optional constructor
     * params are never auto-wired), and the pattern pass silently never runs in production.
     *
     * @return void
     */
    public function testDiXmlWiresTheTextRedactorSoThePatternPassIsNotInert(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        self::assertNotFalse($xml, 'etc/di.xml is not readable.');

        $arguments = $xml->xpath(
            '//type[@name="Magebit\Mcp\Model\AuditLog\PiiRedactor"]/arguments/argument[@name="textRedactor"]'
        );
        self::assertNotEmpty($arguments, 'PiiRedactor has no textRedactor wiring in di.xml.');

        $argument = $arguments[0];
        $xsiAttributes = $argument->attributes('http://www.w3.org/2001/XMLSchema-instance');
        self::assertSame('object', (string) $xsiAttributes['type']);
        self::assertSame('Magebit\Mcp\Model\AuditLog\TextRedactor', ltrim((string) $argument, '\\'));
    }
}
