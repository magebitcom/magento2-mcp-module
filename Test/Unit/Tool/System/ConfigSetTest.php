<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\ConfigSet;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigSetTest extends TestCase
{
    /** @var ConfigWritePolicy&MockObject */
    private ConfigWritePolicy $policy;

    /** @var SensitiveFieldGuard&MockObject */
    private SensitiveFieldGuard $guard;

    /** @var ConfigPathWriter&MockObject */
    private ConfigPathWriter $writer;

    /** @var Structure&MockObject */
    private Structure $structure;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->policy = $this->createMock(ConfigWritePolicy::class);
        $this->guard = $this->createMock(SensitiveFieldGuard::class);
        $this->writer = $this->createMock(ConfigPathWriter::class);
        $this->structure = $this->createMock(Structure::class);
    }

    /**
     * @return ConfigSet
     */
    private function tool(): ConfigSet
    {
        return new ConfigSet($this->policy, $this->guard, $this->writer, $this->structure);
    }

    /**
     * Every gate but the one under test passes, so a refusal can only come from that gate.
     *
     * @return void
     */
    private function openEveryOtherGate(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->structure->method('getElementByConfigPath')->willReturn($this->createMock(Field::class));
        $this->writer->method('currentValue')->willReturn('shipping');
    }

    public function testToolIsWriteGatedAndRequiresConfirmation(): void
    {
        $tool = $this->tool();

        $this->assertSame('system.config.set', $tool->getName());
        $this->assertSame('Magebit_Mcp::tool_system_config_set', $tool->getAclResource());
        $this->assertSame(WriteMode::WRITE, $tool->getWriteMode());
        $this->assertTrue($tool->getConfirmationRequired());
        $this->assertSame('Magento_Config::config', $tool->getUnderlyingAclResource());
    }

    public function testScopeEnumMatchesTheWriterVocabulary(): void
    {
        $schema = $this->tool()->getInputSchema();

        $this->assertIsArray($schema['properties']);
        $this->assertSame(['default', 'websites', 'stores'], $schema['properties']['scope']['enum']);
        $this->assertSame(['path', 'value'], $schema['required']);
    }

    public function testPolicyRefusalStopsTheWrite(): void
    {
        $this->openEveryOtherGate();
        $this->policy->method('assertWritable')
            ->willThrowException(new LocalizedException(__('not in the configured allowlist')));
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not in the configured allowlist');
        $this->tool()->execute(['path' => 'payment/checkmo/active', 'value' => '0']);
    }

    public function testSensitiveFieldIsRefusedEvenWhenThePolicyAllowsIt(): void
    {
        $this->structure->method('getElementByConfigPath')->willReturn($this->createMock(Field::class));
        $this->writer->method('currentValue')->willReturn(null);
        $this->guard->method('rejectionFor')->willReturn('field_type_sensitive');
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('field_type_sensitive');
        $this->tool()->execute(['path' => 'some/group/token', 'value' => 'x']);
    }

    public function testPathAbsentFromSystemXmlIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->structure->method('getElementByConfigPath')->willReturn(null);
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no field in system.xml');
        $this->tool()->execute(['path' => 'custom/group/threshold', 'value' => 'x']);
    }

    /**
     * A Group or Section resolves to a sub-tree, not a writable leaf, so `!== null` is not enough.
     *
     * @return void
     */
    public function testNonFieldElementIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->structure->method('getElementByConfigPath')->willReturn($this->createMock(Group::class));
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no field in system.xml');
        $this->tool()->execute(['path' => 'tax/calculation/x', 'value' => 'x']);
    }

    public function testAPermittedWriteReportsTheOldAndNewValue(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->once())
            ->method('write')
            ->with('tax/calculation/based_on', 'billing', 'default', null);

        $result = $this->tool()->execute(['path' => 'tax/calculation/based_on', 'value' => 'billing']);
        $text = $result->getContent()[0]['text'];
        $this->assertIsString($text);
        $decoded = json_decode($text, true);

        $this->assertIsArray($decoded);
        $this->assertSame('shipping', $decoded['previous_value']);
        $this->assertSame('billing', $decoded['value']);
        $this->assertSame('tax/calculation/based_on', $decoded['path']);
        $this->assertSame('default', $decoded['scope']);
        $this->assertNull($decoded['scope_code']);
        $this->assertSame($decoded, $result->getAuditSummary());
        $this->assertFalse($result->isError());
    }

    public function testTheRequestedScopeAndCodeReachTheWriter(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->once())
            ->method('write')
            ->with('tax/calculation/based_on', 'billing', 'stores', 'default_store');

        $result = $this->tool()->execute([
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'stores',
            'scope_code' => 'default_store',
        ]);
        $text = $result->getContent()[0]['text'];
        $this->assertIsString($text);
        $decoded = json_decode($text, true);

        $this->assertIsArray($decoded);
        $this->assertSame('stores', $decoded['scope']);
        $this->assertSame('default_store', $decoded['scope_code']);
    }

    public function testWebsiteScopeRequiresAScopeCode(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"scope_code" is required');
        $this->tool()->execute([
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'websites',
        ]);
    }

    /**
     * `website` singular was removed deliberately — the writer only knows the plural forms.
     *
     * @return void
     */
    public function testSingularScopeAliasIsRefused(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unsupported scope "website"');
        $this->tool()->execute([
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'website',
            'scope_code' => 'base',
        ]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function missingArgumentProvider(): array
    {
        return [
            'no path' => [['value' => 'billing'], 'path'],
            'blank path' => [['path' => '', 'value' => 'billing'], 'path'],
            'non-string path' => [['path' => ['a'], 'value' => 'billing'], 'path'],
            'no value' => [['path' => 'tax/calculation/based_on'], 'value'],
            'non-string value' => [['path' => 'tax/calculation/based_on', 'value' => 1], 'value'],
        ];
    }

    /**
     * @dataProvider missingArgumentProvider
     * @param array<string, mixed> $arguments
     * @param string $expected
     * @return void
     */
    public function testMissingOrMistypedArgumentsAreRefused(array $arguments, string $expected): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf('Parameter "%s" is required.', $expected));
        $this->tool()->execute($arguments);
    }

    public function testContextualAclResolvesToTheSectionResource(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getAttribute')->with('resource')->willReturn('Magento_Tax::config_tax');
        $this->writer->method('sectionFor')->willReturn($section);

        $this->assertSame(
            ['Magento_Tax::config_tax'],
            $this->tool()->getContextualAclResources(['path' => 'tax/calculation/based_on'])
        );
    }

    public function testContextualAclIsEmptyWhenTheSectionDeclaresNoResource(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getAttribute')->with('resource')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($section);

        $this->assertSame(
            [],
            $this->tool()->getContextualAclResources(['path' => 'tax/calculation/based_on'])
        );
    }

    /**
     * A blank entry is a hard deny in the dispatcher, so it must never be returned in place of none.
     *
     * @return void
     */
    public function testContextualAclIsEmptyWhenTheSectionResourceIsBlank(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getAttribute')->with('resource')->willReturn('');
        $this->writer->method('sectionFor')->willReturn($section);

        $this->assertSame(
            [],
            $this->tool()->getContextualAclResources(['path' => 'tax/calculation/based_on'])
        );
    }

    public function testContextualAclIsEmptyForAnUnresolvablePath(): void
    {
        $this->writer->method('sectionFor')->willReturn(null);

        $this->assertSame([], $this->tool()->getContextualAclResources(['path' => 'nonsense']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusablePathProvider(): array
    {
        return [
            'absent' => [[]],
            'blank' => [['path' => '']],
            'not a string' => [['path' => ['tax/calculation/based_on']]],
        ];
    }

    /**
     * Contextual resolution runs before schema validation, so the arguments may be anything.
     *
     * @dataProvider unusablePathProvider
     * @param array<string, mixed> $arguments
     * @return void
     */
    public function testContextualAclIsEmptyWithoutAUsablePath(array $arguments): void
    {
        $this->writer->expects($this->never())->method('sectionFor');

        $this->assertSame([], $this->tool()->getContextualAclResources($arguments));
    }
}
