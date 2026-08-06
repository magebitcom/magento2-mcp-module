<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Tool\System\ConfigGet;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigGetTest extends TestCase
{
    /**
     * @phpstan-var ScopeConfigInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @phpstan-var Structure&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Structure&MockObject $configStructure;

    private ConfigGet $tool;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configStructure = $this->createMock(Structure::class);
        $this->tool = new ConfigGet($this->scopeConfig, new SensitiveFieldGuard($this->configStructure));
    }

    public function testGroupPathIsRejected(): void
    {
        // A 3-segment path that resolves to a Group (rather than a Field) must
        // be refused — otherwise `ScopeConfigInterface::getValue()` would
        // return the entire group sub-tree, bypassing per-field sensitivity
        // checks and leaking sibling secrets (CVE-class info disclosure).
        $group = $this->createMock(Group::class);
        $this->configStructure
            ->expects(self::once())
            ->method('getElementByConfigPath')
            ->with('payment/braintree/anything')
            ->willReturn($group);
        $this->scopeConfig->expects(self::never())->method('getValue');

        $result = $this->tool->execute(['path' => 'payment/braintree/anything']);

        $audit = $result->getAuditSummary();
        self::assertTrue($audit['forbidden']);
        self::assertSame('non_field_path', $audit['reason']);
    }

    public function testEncryptedFieldIsRejected(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('text');
        $field->method('getAttribute')
            ->with('backend_model')
            ->willReturn(\Magento\Config\Model\Config\Backend\Encrypted::class);
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturn($field);
        $this->scopeConfig->expects(self::never())->method('getValue');

        $result = $this->tool->execute(['path' => 'payment/braintree/sandbox_private_key']);

        $audit = $result->getAuditSummary();
        self::assertTrue($audit['forbidden']);
        self::assertSame('encrypted_backend_model', $audit['reason']);
    }

    public function testKeywordBlockedPath(): void
    {
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturn(null);
        $this->scopeConfig->expects(self::never())->method('getValue');

        $result = $this->tool->execute(['path' => 'custom/api/api_key']);

        $audit = $result->getAuditSummary();
        self::assertTrue($audit['forbidden']);
        self::assertSame('path_keyword_blocked', $audit['reason']);
    }

    public function testRegularFieldPathReturnsValue(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('text');
        $field->method('getAttribute')
            ->with('backend_model')
            ->willReturn(null);
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturn($field);
        $this->scopeConfig
            ->expects(self::once())
            ->method('getValue')
            ->with('general/store_information/name', 'default', null)
            ->willReturn('Acme Storefront');

        $result = $this->tool->execute(['path' => 'general/store_information/name']);

        $audit = $result->getAuditSummary();
        self::assertArrayNotHasKey('forbidden', $audit);
        self::assertSame('general/store_information/name', $audit['path']);

        $decoded = $this->decodeContent($result->getContent());
        self::assertFalse($decoded['forbidden']);
        self::assertSame('Acme Storefront', $decoded['value']);
    }

    public function testDeeperLeafPathIsAllowed(): void
    {
        // Real Magento has 6-segment cron leaves like
        // `crontab/default/jobs/analytics_collect_data/schedule/cron_expr`.
        // Restricting to exactly 3 segments would break those.
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturn(null);
        $this->scopeConfig
            ->expects(self::once())
            ->method('getValue')
            ->willReturn('0 2 * * *');

        $result = $this->tool->execute([
            'path' => 'crontab/default/jobs/analytics_collect_data/schedule/cron_expr',
        ]);

        $decoded = $this->decodeContent($result->getContent());
        self::assertFalse($decoded['forbidden']);
        self::assertSame('0 2 * * *', $decoded['value']);
    }

    public function testArrayResultIsRefused(): void
    {
        // Backstop for the group-bypass: even if `getElementByConfigPath`
        // returns null (path has no system.xml entry), ScopeConfig may still
        // hand back an associative sub-tree from cached config — that must
        // not leak.
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturn(null);
        $this->scopeConfig
            ->method('getValue')
            ->willReturn([
                'sandbox_private_key' => 'SECRET',
                'environment' => 'sandbox',
            ]);

        $result = $this->tool->execute(['path' => 'payment/custom/sandbox']);

        $audit = $result->getAuditSummary();
        self::assertTrue($audit['forbidden']);
        self::assertSame('non_leaf_value', $audit['reason']);
        $decoded = $this->decodeContent($result->getContent());
        self::assertTrue($decoded['forbidden']);
        self::assertNull($decoded['value']);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function decodeContent(array $content): array
    {
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
