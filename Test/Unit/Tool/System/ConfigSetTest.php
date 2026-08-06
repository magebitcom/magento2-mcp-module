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

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->policy = $this->createMock(ConfigWritePolicy::class);
        $this->guard = $this->createMock(SensitiveFieldGuard::class);
        $this->writer = $this->createMock(ConfigPathWriter::class);
    }

    /**
     * @return ConfigSet
     */
    private function tool(): ConfigSet
    {
        return new ConfigSet($this->policy, $this->guard, $this->writer);
    }

    /**
     * @param string|null $resource
     * @return Section&MockObject
     */
    private function section(?string $resource): Section
    {
        $section = $this->createMock(Section::class);
        $section->method('getAttribute')->with('resource')->willReturn($resource);

        return $section;
    }

    /**
     * Every gate but the one under test passes, so a refusal can only come from that gate.
     *
     * @return void
     */
    private function openEveryOtherGate(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));
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
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));
        $this->writer->method('currentValue')->willReturn(null);
        $this->guard->method('rejectionFor')->willReturn('field_type_sensitive');
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('field_type_sensitive');
        $this->tool()->execute(['path' => 'some/group/token', 'value' => 'x']);
    }

    /**
     * The writer owns the system.xml, redirect, file-upload and env-lock refusals; the tool must let
     * their messages through rather than restating them.
     *
     * @return void
     */
    public function testAWriterRefusalReachesTheCallerUnchanged(): void
    {
        $this->openEveryOtherGate();
        $this->writer->method('write')->willThrowException(
            new LocalizedException(__('is not declared as a field in system.xml'))
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not declared as a field in system.xml');
        $this->tool()->execute(['path' => 'custom/group/threshold', 'value' => 'x']);
    }

    /**
     * Section::isAllowed() is false for a section with no <resource>, so the admin UI refuses it for
     * every role. The contextual gate reads [] as "no gate", so execute() has to make the refusal.
     *
     * @return void
     */
    public function testASectionThatDeclaresNoAdminResourceIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section(null));
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('declares no admin permission');
        $this->tool()->execute([
            'path' => 'web_api/graphql/include_dynamic_attributes_as_entity_type_fields',
            'value' => '1',
        ]);
    }

    /**
     * A blank <resource> is the same hole as a missing one, and returns [] from the same helper.
     *
     * @return void
     */
    public function testASectionWithABlankAdminResourceIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section(''));
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('declares no admin permission');
        $this->tool()->execute(['path' => 'custom/group/field', 'value' => '1']);
    }

    /**
     * An unresolvable section is not a permission hole — the writer refuses the path outright, with
     * a better message than this gate could give.
     *
     * @return void
     */
    public function testAnUnresolvableSectionIsLeftToTheWriter(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn(null);
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->once())->method('write');

        $this->tool()->execute(['path' => 'custom/group/field', 'value' => '1']);
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
