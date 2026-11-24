<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Api\BulkContextualAclAwareInterface;
use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\BulkToolLifecycleInterface;
use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Model\Tool\Bulk\BatchResult;
use Magebit\Mcp\Model\Tool\Bulk\BulkErrorCode;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magebit\Mcp\Tool\System\ConfigSet;
use Magento\Config\Model\Config as AdminConfig;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\App\ScopeResolverPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
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

    /** @var Manager&MockObject */
    private Manager $cacheManager;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->policy = $this->createMock(ConfigWritePolicy::class);
        $this->guard = $this->createMock(SensitiveFieldGuard::class);
        $this->writer = $this->createMock(ConfigPathWriter::class);
        $this->cacheManager = $this->createMock(Manager::class);
    }

    /**
     * @param ConfigPathWriter|null $writer A real writer instead of the mocked one.
     * @return ConfigSet
     */
    private function tool(?ConfigPathWriter $writer = null): ConfigSet
    {
        return new ConfigSet($this->policy, $this->guard, $writer ?? $this->writer, $this->cacheManager);
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

    public function testToolIsOnTheBulkContractAndOffTheSingleCallOnes(): void
    {
        $tool = $this->tool();

        $this->assertInstanceOf(BulkToolInterface::class, $tool);
        $this->assertInstanceOf(BulkToolLifecycleInterface::class, $tool);
        $this->assertInstanceOf(BulkContextualAclAwareInterface::class, $tool);
        $this->assertNotInstanceOf(ToolInterface::class, $tool);
        $this->assertNotInstanceOf(ContextualAclAwareInterface::class, $tool);
        $this->assertSame('system.config.set', $tool->getName());
        $this->assertSame('Magebit_Mcp::tool_system_config_set', $tool->getAclResource());
        $this->assertSame(WriteMode::WRITE, $tool->getWriteMode());
        $this->assertTrue($tool->getConfirmationRequired());
        $this->assertSame('Magento_Config::config', $tool->getUnderlyingAclResource());
        $this->assertSame('path', $tool->getItemKeyField());
    }

    public function testCapsAre50(): void
    {
        $this->assertSame(50, $this->tool()->getSyncCap());
        $this->assertSame(50, $this->tool()->getAsyncCeiling());
    }

    public function testItemSchemaKeepsThePerItemRules(): void
    {
        $schema = $this->tool()->getItemSchema();

        $this->assertIsArray($schema['properties']);
        $this->assertSame(['default', 'websites', 'stores'], $schema['properties']['scope']['enum']);
        $this->assertSame(['path', 'value'], $schema['required']);
        $this->assertSame('^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+){2,}$', $schema['properties']['path']['pattern']);
        $this->assertSame(1, $schema['properties']['scope_code']['minLength']);
    }

    public function testDescriptionIsAgentFacing(): void
    {
        $description = $this->tool()->getDescription();

        $this->assertStringContainsString('in order', $description);
        $this->assertStringContainsString('including the field\'s own value validation', $description);
        $this->assertStringNotContainsString('not the value\'s own validation', $description);
        $this->assertDoesNotMatchRegularExpression('/\bsystem\.[a-z]+\.[a-z]+/', $description);
        $this->assertStringNotContainsString('single', $description);
    }

    public function testTwoPathsSaved(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->exactly(2))->method('write');

        $first = $this->tool()->processItem(['path' => 'tax/calculation/based_on', 'value' => 'billing'], false);
        $second = $this->tool()->processItem(['path' => 'tax/calculation/algorithm', 'value' => 'X'], false);

        foreach ([$first, $second] as $result) {
            $this->assertSame(ItemResult::STATUS_OK, $result->getStatus());
            $this->assertSame('previous: shipping', $result->getMessage());
            $this->assertNull($result->getId());
        }
    }

    public function testAPreviousValueThatIsNotSetIsSaidSo(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));
        $this->writer->method('currentValue')->willReturn(null);

        $result = $this->tool()->processItem(['path' => 'tax/calculation/based_on', 'value' => 'billing'], false);

        $this->assertSame('previous: (not set)', $result->getMessage());
    }

    public function testTheRequestedScopeAndCodeReachTheWriter(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->once())
            ->method('write')
            ->with('tax/calculation/based_on', 'billing', 'stores', 'default_store');

        $result = $this->tool()->processItem([
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'stores',
            'scope_code' => 'default_store',
        ], false);

        $this->assertSame(ItemResult::STATUS_OK, $result->getStatus());
    }

    public function testPolicyRefusalIsItemError(): void
    {
        $this->openEveryOtherGate();
        $this->policy->method('assertWritable')->willReturnCallback(
            function (string $path): void {
                if ($path === 'payment/checkmo/active') {
                    throw new LocalizedException(__('is protected by the MCP write policy'));
                }
            }
        );
        $this->writer->expects($this->once())->method('write')
            ->with('tax/calculation/based_on', 'billing', 'default', null);

        $tool = $this->tool();
        $refused = $tool->processItem(['path' => 'payment/checkmo/active', 'value' => '0'], false);
        $saved = $tool->processItem(['path' => 'tax/calculation/based_on', 'value' => 'billing'], false);

        $this->assertSame(ItemResult::STATUS_ERROR, $refused->getStatus());
        $this->assertSame(BulkErrorCode::FORBIDDEN, $refused->getErrorCode());
        $this->assertSame('is protected by the MCP write policy', $refused->getMessage());
        $this->assertSame(ItemResult::STATUS_OK, $saved->getStatus());
    }

    public function testSensitiveFieldIsItemError(): void
    {
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));
        $this->guard->method('rejectionFor')->willReturn('field_type_sensitive');
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem(['path' => 'some/group/token', 'value' => 'x'], false);

        $this->assertSame(BulkErrorCode::FORBIDDEN, $result->getErrorCode());
        $this->assertSame(
            'Path "some/group/token" cannot be written over MCP (field_type_sensitive). Use the admin UI or CLI.',
            $result->getMessage()
        );
    }

    /**
     * The writer owns the system.xml, redirect, file-upload and env-lock refusals; the tool lets their
     * messages through rather than restating them.
     *
     * @return void
     */
    public function testAWriterRefusalIsAValidationFailureWithItsOwnMessage(): void
    {
        $this->openEveryOtherGate();
        $this->writer->method('write')->willThrowException(
            new LocalizedException(__('is not declared as a field in system.xml'))
        );

        $result = $this->tool()->processItem(['path' => 'custom/group/threshold', 'value' => 'x'], false);

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $this->assertSame('is not declared as a field in system.xml', $result->getMessage());
    }

    /**
     * Section::isAllowed() is false for a section with no <resource>, so the admin UI refuses it for
     * every role. The item ACL returns '' for it, and processItem() backs that with a refusal.
     *
     * @return void
     */
    public function testASectionThatDeclaresNoAdminResourceIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section(null));
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem([
            'path' => 'web_api/graphql/include_dynamic_attributes_as_entity_type_fields',
            'value' => '1',
        ], false);

        $this->assertSame(BulkErrorCode::FORBIDDEN, $result->getErrorCode());
        $this->assertStringContainsString('declares no admin permission', (string) $result->getMessage());
    }

    public function testASectionWithABlankAdminResourceIsRefused(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section(''));
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem(['path' => 'custom/group/field', 'value' => '1'], false);

        $this->assertSame(BulkErrorCode::FORBIDDEN, $result->getErrorCode());
    }

    /**
     * An unresolvable section is not a permission hole: the writer refuses the path outright, with a
     * better message than this gate could give.
     *
     * @return void
     */
    public function testAnUnresolvableSectionIsLeftToTheWriter(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn(null);
        $this->writer->method('currentValue')->willReturn(null);
        $this->writer->expects($this->once())->method('write');

        $result = $this->tool()->processItem(['path' => 'custom/group/field', 'value' => '1'], false);

        $this->assertSame(ItemResult::STATUS_OK, $result->getStatus());
    }

    public function testAScopeCodeIsRequiredBelowDefaultScope(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem(
            ['path' => 'tax/calculation/based_on', 'value' => 'billing', 'scope' => 'websites'],
            false
        );

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $this->assertStringContainsString('"scope_code" is required', (string) $result->getMessage());
    }

    /**
     * `website` singular was removed deliberately; the writer only knows the plural forms.
     *
     * @return void
     */
    public function testSingularScopeAliasIsRefused(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem([
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'website',
            'scope_code' => 'base',
        ], false);

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $this->assertStringContainsString('Unsupported scope "website"', (string) $result->getMessage());
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
     * @param array<string, mixed> $item
     * @param string $expected
     * @return void
     */
    public function testMissingOrMistypedFieldsAreValidationFailures(array $item, string $expected): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem($item, false);

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $this->assertSame(sprintf('Parameter "%s" is required.', $expected), $result->getMessage());
    }

    public function testDryRunWritesNothingAndReadsPrevious(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->once())->method('validate')
            ->with('tax/calculation/based_on', 'billing', 'default', null);
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem(['path' => 'tax/calculation/based_on', 'value' => 'billing'], true);

        $this->assertSame(ItemResult::STATUS_OK, $result->getStatus());
        $this->assertSame('previous: shipping', $result->getMessage());
    }

    /**
     * @return array<string, array{0: string, 1: array{status: string, error_code: string|null, message: string|null}}>
     */
    public static function backendValueProvider(): array
    {
        return [
            'value the backend model refuses' => [
                'nowhere',
                [
                    'status' => 'error',
                    'error_code' => 'VALIDATION_FAILED',
                    'message' => 'The tax basis "nowhere" is not an option.',
                ],
            ],
            'value the backend model accepts' => [
                'billing',
                ['status' => 'ok', 'error_code' => null, 'message' => 'previous: shipping'],
            ],
        ];
    }

    /**
     * PR #48 review round 3 (R3): a dry run gives the same item row as the real write, because both run the
     * field's backend model; the dry run never saves.
     *
     * @dataProvider backendValueProvider
     * @param string $value
     * @param array{status: string, error_code: string|null, message: string|null} $expected
     * @return void
     */
    public function testADryRunGivesTheRowTheRealWriteGives(string $value, array $expected): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $item = ['path' => 'tax/calculation/based_on', 'value' => $value];
        $rowKeys = ['status' => 1, 'error_code' => 1, 'message' => 1];

        $saves = 0;
        $dryTool = $this->tool($this->realWriter($this->savingFactory($saves)));
        $dryRow = array_intersect_key($dryTool->processItem($item, true)->toRow(0, 'x'), $rowKeys);
        $this->assertSame(0, $saves, 'a dry run never saves');

        $realTool = $this->tool($this->realWriter($this->savingFactory($saves)));
        $realRow = array_intersect_key($realTool->processItem($item, false)->toRow(0, 'x'), $rowKeys);

        $this->assertSame($expected, $dryRow);
        $this->assertSame($dryRow, $realRow);
        $this->assertSame($expected['status'] === 'ok' ? 1 : 0, $saves);
    }

    /**
     * A ConfigPathWriter on mocked Magento services whose field `tax/calculation/based_on` has a backend model
     * refusing the value `nowhere` in beforeSave(). The backend's save-path methods after beforeSave() must never
     * run in a dry run.
     *
     * @param ConfigFactory $factory
     * @return ConfigPathWriter
     */
    private function realWriter(ConfigFactory $factory): ConfigPathWriter
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn(['id' => 'tax', 'resource' => 'Magento_Tax::config_tax']);
        $section->method('getAttribute')->with('resource')->willReturn('Magento_Tax::config_tax');
        $field = $this->createMock(Field::class);
        $field->method('getConfigPath')->willReturn(null);
        $field->method('getAttribute')->willReturn(null);
        $field->method('getType')->willReturn('select');
        $field->method('hasBackendModel')->willReturn(true);
        $field->method('getBackendModel')->willReturnCallback(fn (): Value => $this->refusingBackend());
        $field->method('getData')->willReturn(['id' => 'based_on']);
        $structure = $this->createMock(Structure::class);
        $structure->method('getFieldPaths')->willReturn(['tax/calculation/based_on' => ['tax/calculation/based_on']]);
        $structure->method('getElement')->willReturnCallback(
            static fn (string $id): object => $id === 'tax' ? $section : $field
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('shipping');
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturn(false);
        $scope = $this->createMock(AppScopeInterface::class);
        $scope->method('getId')->willReturn(0);
        $scope->method('getCode')->willReturn('default');
        $resolver = $this->createMock(ScopeResolverInterface::class);
        $resolver->method('getScope')->willReturn($scope);
        $pool = $this->createMock(ScopeResolverPool::class);
        $pool->method('get')->willReturn($resolver);

        return new ConfigPathWriter(
            $factory,
            $structure,
            $scopeConfig,
            $settingChecker,
            $this->createMock(ScopeCodeResolver::class),
            new ConfigPathFormat(),
            $pool
        );
    }

    /**
     * Stands in for Magento's save path: Config::save() hands the value to the field's backend model, whose
     * resource save runs validateBeforeSave() and beforeSave() before it writes.
     *
     * @param int $saves Counts the rows that would have been written.
     * @return ConfigFactory&MockObject
     */
    private function savingFactory(int &$saves): ConfigFactory
    {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->method('create')->willReturnCallback(function (array $arguments) use (&$saves): AdminConfig {
            $groups = $arguments['data']['groups'];
            $value = $groups['calculation']['fields']['based_on']['value'];
            $config = $this->createMock(AdminConfig::class);
            $config->method('save')->willReturnCallback(function () use ($value, $config, &$saves): AdminConfig {
                $backend = $this->refusingBackend();
                $backend->setData('value', $value);
                $backend->validateBeforeSave();
                $backend->beforeSave();
                $saves++;
                return $config;
            });
            return $config;
        });

        return $factory;
    }

    /**
     * @return Value&MockObject
     */
    private function refusingBackend(): Value
    {
        $backend = $this->getMockBuilder(Value::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateBeforeSave', 'beforeSave', 'save', 'afterSave'])
            ->getMock();
        $backend->method('beforeSave')->willReturnCallback(function () use ($backend): Value {
            if ($backend->getData('value') === 'nowhere') {
                throw new LocalizedException(new Phrase('The tax basis "nowhere" is not an option.'));
            }
            return $backend;
        });
        $backend->expects($this->never())->method('save');
        $backend->expects($this->never())->method('afterSave');

        return $backend;
    }

    public function testDryRunStillRunsThePolicyGuardAndWriterRefusals(): void
    {
        $this->openEveryOtherGate();
        $this->writer->method('validate')->willThrowException(new LocalizedException(__('is locked by env.php')));
        $this->writer->expects($this->never())->method('write');

        $result = $this->tool()->processItem(['path' => 'tax/calculation/based_on', 'value' => 'billing'], true);

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $this->assertSame('is locked by env.php', $result->getMessage());
    }

    public function testItemAclIsTheSectionResource(): void
    {
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));

        $this->assertSame(
            ['Magento_Tax::config_tax'],
            $this->tool()->getItemAclResources(['path' => 'tax/calculation/based_on'])
        );
    }

    /**
     * The executor reads '' as FORBIDDEN and [] as "no gate", so a section with no usable resource
     * has to answer ''.
     *
     * @return void
     */
    public function testItemAclIsBlankWhenTheSectionDeclaresNoResource(): void
    {
        $this->writer->method('sectionFor')->willReturn($this->section(null));

        $this->assertSame([''], $this->tool()->getItemAclResources(['path' => 'web_api/a/b']));
    }

    public function testItemAclIsBlankWhenTheSectionResourceIsBlank(): void
    {
        $this->writer->method('sectionFor')->willReturn($this->section(''));

        $this->assertSame([''], $this->tool()->getItemAclResources(['path' => 'custom/a/b']));
    }

    public function testItemAclIsEmptyForAnUnresolvablePath(): void
    {
        $this->writer->method('sectionFor')->willReturn(null);

        $this->assertSame([], $this->tool()->getItemAclResources(['path' => 'nonsense']));
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
     * @dataProvider unusablePathProvider
     * @param array<string, mixed> $item
     * @return void
     */
    public function testItemAclIsEmptyWithoutAUsablePath(array $item): void
    {
        $this->writer->expects($this->never())->method('sectionFor');

        $this->assertSame([], $this->tool()->getItemAclResources($item));
    }

    public function testDuplicateRefusesWholeCall(): void
    {
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Duplicate item for tax/calculation/based_on (default/); send one value per path and scope.'
        );
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a'],
            ['path' => 'tax/calculation/algorithm', 'value' => 'b'],
            ['path' => 'tax/calculation/based_on', 'value' => 'c'],
        ], false);
    }

    public function testDuplicateCheckNormalisesTheDefaultScope(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Duplicate item for tax/calculation/based_on');
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'default'],
            ['path' => 'tax/calculation/based_on', 'value' => 'c', 'scope' => 'default', 'scope_code' => ''],
        ], true);
    }

    public function testDuplicateMessageNamesScopeAndCode(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Duplicate item for tax/calculation/based_on (stores/default_store); send one value per path and scope.'
        );
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'stores', 'scope_code' => 'default_store'],
            ['path' => 'tax/calculation/based_on', 'value' => 'c', 'scope' => 'stores', 'scope_code' => 'default_store'],
        ], false);
    }

    public function testSamePathInDifferentScopesIsNotADuplicate(): void
    {
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a'],
            ['path' => 'tax/calculation/based_on', 'value' => 'b', 'scope' => 'stores', 'scope_code' => 'one'],
            ['path' => 'tax/calculation/based_on', 'value' => 'c', 'scope' => 'stores', 'scope_code' => 'two'],
            ['path' => 'tax/calculation/based_on', 'value' => 'd', 'scope' => 'websites', 'scope_code' => 'one'],
        ], false);

        $this->addToAssertionCount(1);
    }

    /**
     * PR #48 review round 4 (C1): a website's id and its code name the same row, so the duplicate check keys on the
     * id the writer resolves, not on the text sent.
     *
     * @return void
     */
    public function testDuplicateCheckResolvesAWebsiteIdAndCodeToTheSameScope(): void
    {
        $this->givenScopeIds(['websites' => ['1' => 1, 'base' => 1]]);
        $this->writer->expects($this->never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Duplicate item for tax/calculation/based_on (websites/base); send one value per path and scope.'
        );
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'websites', 'scope_code' => '1'],
            ['path' => 'tax/calculation/based_on', 'value' => 'b', 'scope' => 'websites', 'scope_code' => 'base'],
        ], false);
    }

    /**
     * @return void
     */
    public function testDuplicateCheckResolvesAStoreIdAndCodeToTheSameScope(): void
    {
        $this->givenScopeIds(['stores' => ['default' => 1, '1' => 1]]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Duplicate item for tax/calculation/based_on (stores/1)');
        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'stores', 'scope_code' => 'default'],
            ['path' => 'tax/calculation/based_on', 'value' => 'b', 'scope' => 'stores', 'scope_code' => '1'],
        ], true);
    }

    /**
     * @return void
     */
    public function testTwoDifferentWebsitesAreNotADuplicate(): void
    {
        $this->givenScopeIds(['websites' => ['1' => 1, 'base' => 1, 'second' => 2]]);

        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'websites', 'scope_code' => 'base'],
            ['path' => 'tax/calculation/based_on', 'value' => 'b', 'scope' => 'websites', 'scope_code' => 'second'],
            // Website id 1 in the store scope is another row.
            ['path' => 'tax/calculation/based_on', 'value' => 'c', 'scope' => 'stores', 'scope_code' => '1'],
        ], false);

        $this->addToAssertionCount(1);
    }

    /**
     * A code that does not resolve is left to its own item's error and never collapses with a resolved scope, even
     * when its text is the resolved scope's id.
     *
     * @return void
     */
    public function testAnUnresolvedCodeNeverCollapsesWithAResolvedOne(): void
    {
        $this->givenScopeIds(['websites' => ['base' => 1, 'ghost' => null, '1' => null]]);

        $this->tool()->beforeBatch([
            ['path' => 'tax/calculation/based_on', 'value' => 'a', 'scope' => 'websites', 'scope_code' => 'base'],
            ['path' => 'tax/calculation/based_on', 'value' => 'b', 'scope' => 'websites', 'scope_code' => 'ghost'],
            ['path' => 'tax/calculation/based_on', 'value' => 'c', 'scope' => 'websites', 'scope_code' => '1'],
        ], false);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, array<int|string, int|null>> $ids Canonical id by scope type and scope code.
     * @return void
     */
    private function givenScopeIds(array $ids): void
    {
        $this->writer->method('canonicalScopeId')->willReturnCallback(
            fn (string $scope, ?string $code): ?int => $scope === 'default' ? 0 : ($ids[$scope][(string) $code] ?? null)
        );
    }

    public function testCacheCleanedOnce(): void
    {
        $result = new BatchResult();
        $result->add(0, 'a/b/c', ItemResult::ok(null, 'previous: 1'));
        $result->add(1, 'a/b/d', ItemResult::ok(null, 'previous: 2'));
        $result->add(2, 'a/b/e', ItemResult::error(BulkErrorCode::FORBIDDEN, 'no'));
        $this->cacheManager->expects($this->once())->method('clean')->with(['config']);

        $this->tool()->afterBatch($result, false);
    }

    public function testNoCacheCleanOnADryRun(): void
    {
        $result = new BatchResult();
        $result->add(0, 'a/b/c', ItemResult::ok(null, 'previous: 1'));
        $this->cacheManager->expects($this->never())->method('clean');

        $this->tool()->afterBatch($result, true);
    }

    public function testNoCacheCleanWhenNothingWasSaved(): void
    {
        $result = new BatchResult();
        $result->add(0, 'a/b/c', ItemResult::error(BulkErrorCode::FORBIDDEN, 'no'));
        $this->cacheManager->expects($this->never())->method('clean');

        $this->tool()->afterBatch($result, false);
    }

    public function testToolGivesPerItemAuditDetail(): void
    {
        $this->assertInstanceOf(BulkItemAuditInterface::class, $this->tool());
    }

    public function testAuditDetailOfAWrittenItemHoldsThePreviousAndTheNewValue(): void
    {
        $this->openEveryOtherGate();
        $tool = $this->tool();
        $item = ['path' => 'tax/calculation/based_on', 'value' => 'billing'];

        $result = $tool->processItem($item, false);

        $this->assertSame([
            'path' => 'tax/calculation/based_on',
            'scope' => 'default',
            'scope_code' => null,
            'previous' => 'shipping',
            'value' => 'billing',
        ], $tool->getItemAuditDetail($item, $result, false));
    }

    public function testAuditDetailOfADryRunHoldsTheCurrentAndTheWouldBeValue(): void
    {
        $this->openEveryOtherGate();
        $this->writer->expects($this->never())->method('write');
        $tool = $this->tool();
        $item = [
            'path' => 'tax/calculation/based_on',
            'value' => 'billing',
            'scope' => 'websites',
            'scope_code' => 'base',
        ];

        $result = $tool->processItem($item, true);

        $this->assertSame([
            'path' => 'tax/calculation/based_on',
            'scope' => 'websites',
            'scope_code' => 'base',
            'previous' => 'shipping',
            'value' => 'billing',
        ], $tool->getItemAuditDetail($item, $result, true));
    }

    public function testAuditDetailKeepsAPreviousValueThatIsNotSetAsNull(): void
    {
        $this->guard->method('rejectionFor')->willReturn(null);
        $this->writer->method('sectionFor')->willReturn($this->section('Magento_Tax::config_tax'));
        $this->writer->method('currentValue')->willReturn(null);
        $tool = $this->tool();
        $item = ['path' => 'tax/calculation/based_on', 'value' => 'billing'];

        $detail = $tool->getItemAuditDetail($item, $tool->processItem($item, false), false);

        $this->assertIsArray($detail);
        $this->assertArrayHasKey('previous', $detail);
        $this->assertNull($detail['previous']);
    }

    /**
     * An item refused before the read has no `previous`: the refused value is never read.
     */
    public function testAuditDetailOfARefusedItemHasNoPreviousAndNothingIsRead(): void
    {
        $this->policy->method('assertWritable')->willThrowException(new LocalizedException(__('protected path')));
        $this->writer->expects($this->never())->method('currentValue');
        $tool = $this->tool();
        $item = ['path' => 'payment/checkmo/active', 'value' => '1'];

        $result = $tool->processItem($item, false);

        $this->assertSame(BulkErrorCode::FORBIDDEN, $result->getErrorCode());
        $this->assertSame([
            'path' => 'payment/checkmo/active',
            'scope' => 'default',
            'scope_code' => null,
            'value' => '1',
        ], $tool->getItemAuditDetail($item, $result, false));
    }

    /**
     * Review M1: the value of a path the sensitivity guard refuses is masked, through the same guard call.
     */
    public function testAuditDetailOfASensitivePathMasksTheValue(): void
    {
        $this->guard->method('rejectionFor')->with('carriers/ups/password')->willReturn('field_type_sensitive');
        $this->writer->expects($this->never())->method('currentValue');
        $tool = $this->tool();
        $item = ['path' => 'carriers/ups/password', 'value' => 'S3cr3t'];

        $result = $tool->processItem($item, false);

        $this->assertSame(BulkErrorCode::FORBIDDEN, $result->getErrorCode());
        $this->assertSame([
            'path' => 'carriers/ups/password',
            'scope' => 'default',
            'scope_code' => null,
            'value' => '[sensitive]',
        ], $tool->getItemAuditDetail($item, $result, false));
    }

    /**
     * An item refused by the per-item ACL never reaches processItem(); its sensitive value is still masked.
     */
    public function testAuditDetailMasksASensitiveValueEvenWithoutProcessItem(): void
    {
        $this->guard->method('rejectionFor')->willReturn('encrypted_backend_model');

        $detail = $this->tool()->getItemAuditDetail(
            ['path' => 'payment/braintree/private_key', 'value' => 'abc'],
            ItemResult::error(BulkErrorCode::FORBIDDEN, 'denied'),
            false
        );

        $this->assertSame('[sensitive]', $detail['value'] ?? null);
    }

    /**
     * A guard that throws is treated as a refusal: the value is masked, never written through.
     */
    public function testAuditDetailMasksTheValueWhenTheGuardThrows(): void
    {
        $this->guard->method('rejectionFor')->willThrowException(new \RuntimeException('structure broke'));

        $detail = $this->tool()->getItemAuditDetail(
            ['path' => 'tax/calculation/based_on', 'value' => 'abc'],
            ItemResult::error(BulkErrorCode::ITEM_FAILED, 'Item failed.'),
            false
        );

        $this->assertSame('[sensitive]', $detail['value'] ?? null);
    }

    /**
     * A write that fails after the read still reports what was there.
     */
    public function testAuditDetailOfAFailedWriteKeepsThePreviousValue(): void
    {
        $this->openEveryOtherGate();
        $this->writer->method('write')->willThrowException(new LocalizedException(__('invalid value')));
        $tool = $this->tool();
        $item = ['path' => 'tax/calculation/based_on', 'value' => 'nonsense'];

        $result = $tool->processItem($item, false);

        $this->assertSame(BulkErrorCode::VALIDATION_FAILED, $result->getErrorCode());
        $detail = $tool->getItemAuditDetail($item, $result, false);
        $this->assertSame('shipping', $detail['previous'] ?? null);
    }

    /**
     * The read value belongs to its own item: the next item for another path does not inherit it.
     */
    public function testAuditDetailDoesNotCarryAPreviousValueOverToAnotherItem(): void
    {
        $this->openEveryOtherGate();
        $tool = $this->tool();
        $first = ['path' => 'tax/calculation/based_on', 'value' => 'billing'];
        $tool->processItem($first, false);
        $tool->getItemAuditDetail($first, ItemResult::ok(), false);
        $second = ['path' => 'tax/calculation/algorithm', 'value' => 'X'];

        $detail = $tool->getItemAuditDetail($second, ItemResult::error(BulkErrorCode::FORBIDDEN, 'no'), false);

        $this->assertIsArray($detail);
        $this->assertArrayNotHasKey('previous', $detail);
    }

    public function testAuditDetailWithoutAPathIsNull(): void
    {
        $item = ['value' => '1'];
        $tool = $this->tool();

        $this->assertNull($tool->getItemAuditDetail($item, $tool->processItem($item, false), false));
    }
}
