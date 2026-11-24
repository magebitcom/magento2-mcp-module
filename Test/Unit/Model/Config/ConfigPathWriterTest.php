<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Config\Model\Config as AdminConfig;
use Magento\Config\Model\Config\Backend\File as FileBackend;
use Magento\Config\Model\Config\Backend\Image\Pdf as ImagePdfBackend;
use Magento\Config\Model\Config\Backend\Serialized as SerializedBackend;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Config\Model\Config\Backend\Admin\Custompath;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueInterface;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\App\ScopeResolverPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigPathWriterTest extends TestCase
{
    /**
     * Structural paths system.xml declares, used for the existence half of the resolution.
     */
    private const DECLARED = [
        'tax/calculation/based_on',
        'section/outer/inner/flag',
        'section/a/b/c/flag',
    ];

    /**
     * @param string $sectionId
     * @return Section&MockObject
     */
    private function declaredSection(string $sectionId): Section
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn([
            'id' => $sectionId,
            'label' => 'Some Section',
            'resource' => 'Some_Module::config_' . $sectionId,
            '_elementType' => 'section',
        ]);

        return $section;
    }

    /**
     * Reproduces Structure::_createEmptyElement() — the placeholder returned for an undeclared id.
     *
     * @param string $sectionId
     * @return Section&MockObject
     */
    private function synthesisedSection(string $sectionId): Section
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn([
            'id' => $sectionId,
            'path' => '',
            '_elementType' => 'section',
        ]);

        return $section;
    }

    /**
     * The intercepted flyweight Config::getFieldPath() reads. A plugin-synthesised config_path is
     * indistinguishable from a declared one here, which is the point.
     *
     * @param string|null $configPath
     * @return Field&MockObject
     */
    private function flyweightField(?string $configPath): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getConfigPath')->willReturn($configPath);

        return $field;
    }

    /**
     * @param string|null $backendModel
     * @param string|null $type
     * @return Field&MockObject
     */
    private function fieldWith(?string $backendModel, ?string $type): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getConfigPath')->willReturn(null);
        $field->method('getAttribute')->with('backend_model')->willReturn($backendModel);
        $field->method('getType')->willReturn($type);

        return $field;
    }

    /**
     * getFieldPaths() is modelled as always reporting identity — it walks the raw merged array and
     * cannot see a plugin — so any test that observes a redirect proves the flyweight was consulted.
     *
     * @param list<string>|null $declared
     * @param array<string, string> $redirects Structural path => the config_path its flyweight reports.
     * @param array<string, object> $elements getElement() overrides, by path.
     * @return Structure&MockObject
     */
    private function structure(?array $declared = null, array $redirects = [], array $elements = []): Structure
    {
        $fieldPaths = [];
        foreach ($declared ?? self::DECLARED as $structurePath) {
            $fieldPaths[$structurePath] = [$structurePath];
        }

        $structure = $this->createMock(Structure::class);
        $structure->method('getFieldPaths')->willReturn($fieldPaths);
        $structure->method('getElement')->willReturnCallback(
            function (string $id) use ($redirects, $elements): object {
                if (isset($elements[$id])) {
                    return $elements[$id];
                }

                return str_contains($id, '/')
                    ? $this->flyweightField($redirects[$id] ?? null)
                    : $this->declaredSection($id);
            }
        );

        return $structure;
    }

    /**
     * @param ConfigFactory|null $factory
     * @param Structure|null $structure
     * @param ScopeConfigInterface|null $scopeConfig
     * @param SettingChecker|null $settingChecker
     * @param ScopeCodeResolver|null $scopeCodeResolver
     * @param ScopeResolverPool|null $scopeResolverPool
     * @param array<int|string, string> $dryRunSkippedBackends
     * @return ConfigPathWriter
     */
    private function writer(
        ?ConfigFactory $factory = null,
        ?Structure $structure = null,
        ?ScopeConfigInterface $scopeConfig = null,
        ?SettingChecker $settingChecker = null,
        ?ScopeCodeResolver $scopeCodeResolver = null,
        ?ScopeResolverPool $scopeResolverPool = null,
        array $dryRunSkippedBackends = []
    ): ConfigPathWriter {
        if ($settingChecker === null) {
            $settingChecker = $this->createMock(SettingChecker::class);
            $settingChecker->method('isReadOnly')->willReturn(false);
        }
        if ($scopeCodeResolver === null) {
            $scopeCodeResolver = $this->createMock(ScopeCodeResolver::class);
            $scopeCodeResolver->method('resolve')->willReturnArgument(1);
        }

        return new ConfigPathWriter(
            $factory ?? $this->createMock(ConfigFactory::class),
            $structure ?? $this->structure(),
            $scopeConfig ?? $this->createMock(ScopeConfigInterface::class),
            $settingChecker,
            $scopeCodeResolver,
            new ConfigPathFormat(),
            $scopeResolverPool ?? $this->scopeResolverPool(),
            $dryRunSkippedBackends
        );
    }

    /**
     * Resolves every scope type: the default scope to id 0, a website or store code to id 1 with that code.
     *
     * @param list<array{0: string, 1: mixed}>|null $asked Collects [scope type, identifier] per resolution.
     * @return ScopeResolverPool&MockObject
     */
    private function scopeResolverPool(?array &$asked = null): ScopeResolverPool
    {
        $pool = $this->createMock(ScopeResolverPool::class);
        $pool->method('get')->willReturnCallback(function (string $type) use (&$asked): ScopeResolverInterface {
            $resolver = $this->createMock(ScopeResolverInterface::class);
            $resolver->method('getScope')->willReturnCallback(
                function ($id = null) use ($type, &$asked): AppScopeInterface {
                    $asked[] = [$type, $id];
                    if ($id === 'missing') {
                        throw NoSuchEntityException::singleField('code', $id);
                    }
                    $scope = $this->createMock(AppScopeInterface::class);
                    $scope->method('getId')->willReturn($id === null ? 0 : 1);
                    $scope->method('getCode')->willReturn($id === null ? 'default' : (string) $id);
                    return $scope;
                }
            );
            return $resolver;
        });

        return $pool;
    }

    /**
     * A config value backend whose own methods are real except the save-path ones, which the test watches.
     *
     * @param string|null $refusal Message beforeSave() throws as a LocalizedException, or null to accept.
     * @return Value&MockObject
     */
    private function backend(?string $refusal = null): Value
    {
        $backend = $this->getMockBuilder(Value::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateBeforeSave', 'beforeSave', 'save', 'afterSave', 'getResource'])
            ->getMock();
        $backend->expects($this->never())->method('save');
        $backend->expects($this->never())->method('afterSave');
        $backend->expects($this->never())->method('getResource');
        if ($refusal !== null) {
            $backend->method('beforeSave')->willThrowException(new LocalizedException(new Phrase($refusal)));
        }

        return $backend;
    }

    /**
     * @param ValueInterface $backend
     * @return Structure&MockObject
     */
    private function structureWithBackend(ValueInterface $backend): Structure
    {
        $field = $this->createMock(Field::class);
        $field->method('getConfigPath')->willReturn(null);
        $field->method('getAttribute')->willReturn(null);
        $field->method('getType')->willReturn('select');
        $field->method('hasBackendModel')->willReturn(true);
        $field->method('getBackendModel')->willReturn($backend);
        $field->method('getData')->willReturn(['id' => 'based_on', 'label' => 'Tax Calculation Based On']);

        return $this->structure(null, [], ['tax/calculation/based_on' => $field]);
    }

    /**
     * @param array<string, mixed>|null $expectedData Asserted against the factory argument.
     * @param Structure|null $structure
     * @return array{0: ConfigPathWriter, 1: AdminConfig&MockObject}
     */
    private function writerExpecting(?array $expectedData, ?Structure $structure = null): array
    {
        $adminConfig = $this->createMock(AdminConfig::class);
        $structure = $structure ?? $this->structure();

        $factory = $this->createMock(ConfigFactory::class);
        $create = $factory->expects($this->once())->method('create');
        if ($expectedData !== null) {
            // The structure is asserted on every happy path: Config must resolve the field against
            // the same instance the redirect check just used, not its DI-wired area-scoped default.
            $create->with(['configStructure' => $structure, 'data' => $expectedData]);
        }
        $create->willReturn($adminConfig);

        return [$this->writer($factory, $structure), $adminConfig];
    }

    /**
     * @param Structure|null $structure
     * @param ScopeConfigInterface|null $scopeConfig
     * @param SettingChecker|null $settingChecker
     * @return ConfigPathWriter
     */
    private function writerRefusingToCreate(
        ?Structure $structure = null,
        ?ScopeConfigInterface $scopeConfig = null,
        ?SettingChecker $settingChecker = null
    ): ConfigPathWriter {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->expects($this->never())->method('create');

        return $this->writer($factory, $structure, $scopeConfig, $settingChecker);
    }

    /**
     * @return ConfigFactory&MockObject
     */
    private function savingFactory(): ConfigFactory
    {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->method('create')->willReturn($this->createMock(AdminConfig::class));

        return $factory;
    }

    public function testAThreeSegmentPathBecomesSectionGroupField(): void
    {
        [$writer, $adminConfig] = $this->writerExpecting([
            'section' => 'tax',
            'website' => '',
            'store' => '',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    /**
     * Config is DI-wired to the plain area-scoped Structure, which is empty on the frontend route
     * this module serves. Forwarding ours is what makes the backend model and validation run.
     *
     * @return void
     */
    public function testTheWritersOwnStructureIsHandedToTheConfigModel(): void
    {
        $structure = $this->structure();

        $factory = $this->createMock(ConfigFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->with($this->callback(
                fn (array $arguments): bool => ($arguments['configStructure'] ?? null) === $structure
            ))
            ->willReturn($this->createMock(AdminConfig::class));

        $this->writer($factory, $structure)->write('tax/calculation/based_on', 'total', 'default', null);
    }

    public function testANestedGroupPathNestsTheGroupsArray(): void
    {
        [$writer, $adminConfig] = $this->writerExpecting([
            'section' => 'section',
            'website' => '',
            'store' => '',
            'groups' => [
                'outer' => ['groups' => ['inner' => ['fields' => ['flag' => ['value' => '1']]]]],
            ],
        ]);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('section/outer/inner/flag', '1', 'default', null);
    }

    public function testADeeplyNestedGroupPathNestsOncePerIntermediateGroup(): void
    {
        [$writer, $adminConfig] = $this->writerExpecting([
            'section' => 'section',
            'website' => '',
            'store' => '',
            'groups' => [
                'a' => [
                    'groups' => [
                        'b' => ['groups' => ['c' => ['fields' => ['flag' => ['value' => 'x']]]]],
                    ],
                ],
            ],
        ]);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('section/a/b/c/flag', 'x', 'default', null);
    }

    public function testWebsiteScopeIsPassedThrough(): void
    {
        [$writer] = $this->writerExpecting([
            'section' => 'tax',
            'website' => 'base',
            'store' => '',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);

        $writer->write('tax/calculation/based_on', 'total', 'websites', 'base');
    }

    public function testStoreScopeIsPassedThrough(): void
    {
        [$writer] = $this->writerExpecting([
            'section' => 'tax',
            'website' => '',
            'store' => 'default',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);

        $writer->write('tax/calculation/based_on', 'total', 'stores', 'default');
    }

    public function testADefaultScopeIgnoresAnyScopeCode(): void
    {
        [$writer] = $this->writerExpecting([
            'section' => 'tax',
            'website' => '',
            'store' => '',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);

        $writer->write('tax/calculation/based_on', 'total', 'default', 'base');
    }

    /**
     * An unrecognised scope must not quietly degrade to a default-scope write, which would set the
     * value for every store instead of the one asked for.
     *
     * @return array<string, array{0: string}>
     */
    public static function unknownScopeProvider(): array
    {
        return [
            'singular website' => ['website'],
            'singular store' => ['store'],
            'group' => ['groups'],
            'empty' => [''],
            'mixed case' => ['Websites'],
        ];
    }

    /**
     * @dataProvider unknownScopeProvider
     * @param string $scope
     * @return void
     */
    public function testAnUnknownScopeNameIsRejectedRatherThanTreatedAsDefault(string $scope): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/based_on', 'total', $scope, 'base');
    }

    public function testAWebsiteScopeWithoutACodeIsRejected(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/based_on', 'total', 'websites', null);
    }

    public function testAStoreScopeWithABlankCodeIsRejected(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/based_on', 'total', 'stores', '');
    }

    public function testATooShortPathIsRejected(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation', 'total', 'default', null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPathProvider(): array
    {
        return [
            'single segment' => ['tax'],
            'two segments' => ['tax/calculation'],
            'empty string' => [''],
            'leading slash' => ['/tax/calculation/based_on'],
            'trailing slash' => ['tax/calculation/based_on/'],
            'doubled slash' => ['tax//calculation/based_on'],
            'blank segment' => ['tax/ /based_on'],
            'embedded space' => ['tax/calc ulation/based_on'],
            'leading space' => [' tax/calculation/based_on'],
            'newline' => ["tax/calculation/based_on\n"],
            'non-breaking space' => ["tax/calc\u{00A0}ulation/based_on"],
            'hyphen' => ['tax/calc-ulation/based_on'],
        ];
    }

    /**
     * @dataProvider malformedPathProvider
     * @param string $path
     * @return void
     */
    public function testAMalformedPathIsNeverWritten(string $path): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write($path, 'total', 'default', null);
    }

    /**
     * @dataProvider malformedPathProvider
     * @param string $path
     * @return void
     */
    public function testAMalformedPathHasNoSection(string $path): void
    {
        $structure = $this->createMock(Structure::class);
        $structure->expects($this->never())->method('getElement');

        $this->assertNull($this->writerRefusingToCreate($structure)->sectionFor($path));
    }

    public function testTheRejectionMessageQuotesTheOffendingPathWithoutControlCharacters(): void
    {
        $writer = $this->writerRefusingToCreate();

        try {
            $writer->write("tax/calc\x00ulation", 'total', 'default', null);
            $this->fail('Expected a LocalizedException.');
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('tax/calc ulation', $message);
            $this->assertSame($message, preg_replace('/[[:cntrl:]]/', '', $message));
        }
    }

    public function testTheRejectionMessageIsLengthBounded(): void
    {
        $writer = $this->writerRefusingToCreate();

        try {
            $writer->write(str_repeat('a', 5000), 'total', 'default', null);
            $this->fail('Expected a LocalizedException.');
        } catch (LocalizedException $e) {
            $this->assertLessThan(500, mb_strlen($e->getMessage()));
        }
    }

    /**
     * The invariant this protects: Magento swaps in the field's `<config_path>` at save time, so an
     * allowlisted path can end up writing a row the protected-prefix list forbids.
     *
     * @return void
     */
    public function testAFieldRedirectingViaConfigPathIsRefused(): void
    {
        $requested = 'payment_all_paypal/paypal_payflow_required/partner';
        $structure = $this->structure([$requested], [$requested => 'payment/payflowpro/partner']);

        $writer = $this->writerRefusingToCreate($structure);

        $this->expectException(LocalizedException::class);
        $writer->write($requested, 'abc123', 'default', null);
    }

    /**
     * Magento_Paypal's FieldPlugin::afterGetConfigPath() synthesises `payment/<rest>` for every
     * field under a payment_<country> section that declares no config_path. getFieldPaths() walks
     * the raw merged array and cannot see it, so resolution has to come off the flyweight.
     *
     * @return void
     */
    public function testAPluginSynthesisedConfigPathIsRefusedEvenThoughTheRawStructureShowsIdentity(): void
    {
        $requested = 'payment_us/purchaseorder/sort_order';
        $structure = $this->structure([$requested], [$requested => 'payment/purchaseorder/sort_order']);

        $writer = $this->writerRefusingToCreate($structure);

        $this->expectException(LocalizedException::class);
        $writer->write($requested, '4242', 'default', null);
    }

    public function testTheRedirectRefusalNamesBothTheRequestedAndTheStoredPath(): void
    {
        $requested = 'payment_us/purchaseorder/sort_order';
        $structure = $this->structure([$requested], [$requested => 'payment/purchaseorder/sort_order']);

        try {
            $this->writerRefusingToCreate($structure)->write($requested, '4242', 'default', null);
            $this->fail('Expected a LocalizedException.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString($requested, $e->getMessage());
            $this->assertStringContainsString('payment/purchaseorder/sort_order', $e->getMessage());
        }
    }

    /**
     * A field whose flyweight reports the path it was asked for is not a redirect.
     *
     * @return void
     */
    public function testAConfigPathEqualToTheRequestedPathIsStillWritable(): void
    {
        $structure = $this->structure(null, ['tax/calculation/based_on' => 'tax/calculation/based_on']);

        [$writer, $adminConfig] = $this->writerExpecting(null, $structure);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    /**
     * Config::getFieldPath() only honours a config_path containing an inner slash, so one that does
     * not must not be treated as a redirect either.
     *
     * @return void
     */
    public function testAConfigPathWithoutAnInnerSlashIsIgnoredExactlyAsMagentoIgnoresIt(): void
    {
        $structure = $this->structure(null, ['tax/calculation/based_on' => 'nonsense']);

        [$writer, $adminConfig] = $this->writerExpecting(null, $structure);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    /**
     * Without a declared field there is no backend model and no validation — the raw string would be
     * persisted, which is exactly what routing through the admin save path is supposed to prevent.
     *
     * @return void
     */
    public function testAPathNotDeclaredInSystemXmlIsRefused(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/algorithm', 'garbage', 'default', null);
    }

    public function testValidateRefusesAnUndeclaredPathWithoutSaving(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not declared as a field in system.xml');
        $writer->validate('tax/calculation/algorithm', 'garbage', 'default', null);
    }

    /**
     * PR #48 review round 3 (R3): the dry run hands the field's backend model what Config::_processGroup() hands
     * it, then runs the two checks the resource save runs before writing, and nothing after them.
     *
     * @return void
     */
    public function testValidateRunsTheBackendModelChecksWithoutSaving(): void
    {
        $backend = $this->backend();
        $calls = [];
        $backend->expects($this->once())->method('validateBeforeSave')
            ->willReturnCallback(function () use (&$calls, $backend): Value {
                $calls[] = 'validateBeforeSave';
                return $backend;
            });
        $backend->expects($this->once())->method('beforeSave')
            ->willReturnCallback(function () use (&$calls, $backend): Value {
                $calls[] = 'beforeSave';
                return $backend;
            });
        $asked = [];
        $writer = $this->writer(
            $this->neverCreatingFactory(),
            $this->structureWithBackend($backend),
            null,
            null,
            null,
            $this->scopeResolverPool($asked)
        );

        $writer->validate('tax/calculation/based_on', 'billing', 'websites', 'base');

        $this->assertSame(['validateBeforeSave', 'beforeSave'], $calls);
        $this->assertSame([['websites', 'base']], $asked);
        $this->assertSame('tax/calculation/based_on', $backend->getData('path'));
        $this->assertSame('billing', $backend->getData('value'));
        $this->assertSame('based_on', $backend->getData('field'));
        $this->assertSame('calculation', $backend->getData('group_id'));
        $this->assertSame('websites', $backend->getData('scope'));
        $this->assertSame(1, $backend->getData('scope_id'));
        $this->assertSame('base', $backend->getData('scope_code'));
        $this->assertSame(['based_on' => 'billing'], $backend->getData('fieldset_data'));
        $this->assertSame(
            ['calculation' => ['fields' => ['based_on' => ['value' => 'billing']]]],
            $backend->getData('groups')
        );
        $this->assertSame(
            ['id' => 'based_on', 'label' => 'Tax Calculation Based On'],
            $backend->getData('field_config')
        );
    }

    /**
     * @return void
     */
    public function testValidateResolvesTheDefaultScopeWithoutACode(): void
    {
        $backend = $this->backend();
        $asked = [];
        $writer = $this->writer(
            $this->neverCreatingFactory(),
            $this->structureWithBackend($backend),
            null,
            null,
            null,
            $this->scopeResolverPool($asked)
        );

        $writer->validate('tax/calculation/based_on', 'billing', 'default', null);

        $this->assertSame([['default', null]], $asked);
        $this->assertSame(0, $backend->getData('scope_id'));
        $this->assertSame('default', $backend->getData('scope'));
    }

    /**
     * The backend's refusal reaches the caller as the same LocalizedException the save would raise.
     *
     * @return void
     */
    public function testValidateRaisesTheBackendModelsRefusal(): void
    {
        $writer = $this->writer(
            $this->neverCreatingFactory(),
            $this->structureWithBackend($this->backend('The tax basis "nowhere" is not an option.'))
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The tax basis "nowhere" is not an option.');
        $writer->validate('tax/calculation/based_on', 'nowhere', 'default', null);
    }

    /**
     * The save resolves the scope first (Config::initScope()), so an unknown scope code fails a dry run as well.
     *
     * @return void
     */
    public function testValidateRefusesAnUnknownScopeCodeLikeTheSave(): void
    {
        $writer = $this->writer($this->neverCreatingFactory(), $this->structure());

        $this->expectException(NoSuchEntityException::class);
        $writer->validate('tax/calculation/based_on', 'billing', 'stores', 'missing');
    }

    /**
     * A backend whose beforeSave() changes something outside itself is not called in a dry run; the path checks
     * still run.
     *
     * @return void
     */
    public function testValidateSkipsTheValueCheckOfABackendWithSideEffects(): void
    {
        $backend = $this->getMockBuilder(Custompath::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateBeforeSave', 'beforeSave', 'save', 'afterSave'])
            ->getMock();
        $backend->expects($this->never())->method('validateBeforeSave');
        $backend->expects($this->never())->method('beforeSave');
        $backend->expects($this->never())->method('save');
        $writer = $this->writer(
            $this->neverCreatingFactory(),
            $this->structureWithBackend($backend),
            null,
            null,
            null,
            null,
            [Custompath::class]
        );

        $writer->validate('tax/calculation/based_on', 'secret-admin', 'default', null);
    }

    /**
     * A field without a backend model gets a plain config value, which checks nothing.
     *
     * @return void
     */
    public function testValidateSkipsTheValueCheckWithoutABackendModel(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('hasBackendModel')->willReturn(false);
        $field->expects($this->never())->method('getBackendModel');
        $writer = $this->writer(
            $this->neverCreatingFactory(),
            $this->structure(null, [], ['tax/calculation/based_on' => $field])
        );

        $writer->validate('tax/calculation/based_on', 'billing', 'default', null);
    }

    /**
     * @return ConfigFactory&MockObject
     */
    private function neverCreatingFactory(): ConfigFactory
    {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->expects($this->never())->method('create');

        return $factory;
    }

    public function testValidateAcceptsAWritablePathWithoutSaving(): void
    {
        $writer = $this->writerRefusingToCreate();

        $writer->validate('tax/calculation/based_on', 'total', 'default', null);

        $this->addToAssertionCount(1);
    }

    /**
     * Existence comes from getFieldPaths(), so a flyweight alone must not make a path writable.
     *
     * @return void
     */
    public function testAnUndeclaredPathIsRefusedEvenWhenItsFlyweightLooksLikeAField(): void
    {
        $structure = $this->structure([], [], ['tax/calculation/algorithm' => $this->flyweightField(null)]);

        $writer = $this->writerRefusingToCreate($structure);

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/algorithm', 'garbage', 'default', null);
    }

    public function testAPathWhoseElementIsNotAFieldIsTreatedAsUnredirected(): void
    {
        $structure = $this->structure(
            null,
            [],
            ['tax/calculation/based_on' => $this->createMock(Group::class)]
        );

        [$writer, $adminConfig] = $this->writerExpecting(null, $structure);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function fileUploadFieldProvider(): array
    {
        return [
            'File backend model' => [FileBackend::class, 'text'],
            'File subclass backend model' => [ImagePdfBackend::class, 'text'],
            'backend model with a leading slash' => ['\\' . FileBackend::class, 'text'],
            'image type alone' => [null, 'image'],
            'file type alone' => [null, 'file'],
            'type in mixed case' => [null, 'Image'],
        ];
    }

    /**
     * Backend\File::beforeSave() acts on $_FILES only: a plain string makes it unset the value, so
     * _prepareDataForTable() drops the column and save() reports a change that never happened.
     *
     * @dataProvider fileUploadFieldProvider
     * @param string|null $backendModel
     * @param string|null $type
     * @return void
     */
    public function testAFileUploadFieldIsRefused(?string $backendModel, ?string $type): void
    {
        $structure = $this->structure(
            null,
            [],
            ['tax/calculation/based_on' => $this->fieldWith($backendModel, $type)]
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('takes a file upload');
        $this->writerRefusingToCreate($structure)->write('tax/calculation/based_on', 'logo.png', 'default', null);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function ordinaryFieldProvider(): array
    {
        return [
            'no backend model, plain type' => [null, 'text'],
            'unrelated backend model' => [SerializedBackend::class, 'text'],
            'backend model that does not exist' => ['No\\Such\\Backend\\Model', 'select'],
            'blank backend model' => ['', null],
            'type resembling neither' => [null, 'textarea'],
        ];
    }

    /**
     * @dataProvider ordinaryFieldProvider
     * @param string|null $backendModel
     * @param string|null $type
     * @return void
     */
    public function testAnOrdinaryFieldIsStillWritable(?string $backendModel, ?string $type): void
    {
        $structure = $this->structure(
            null,
            [],
            ['tax/calculation/based_on' => $this->fieldWith($backendModel, $type)]
        );

        [$writer, $adminConfig] = $this->writerExpecting(null, $structure);
        $adminConfig->expects($this->once())->method('save');

        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    /**
     * _processGroup() skips a locked path and save() still returns cleanly, so without this the tool
     * reports a change that never reached the database.
     *
     * @return void
     */
    public function testAPathLockedInTheDeploymentConfigIsRefused(): void
    {
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturn(true);

        $writer = $this->writerRefusingToCreate(null, null, $settingChecker);

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/based_on', 'total', 'default', null);
    }

    public function testTheLockCheckUsesTheRequestedPathAndScope(): void
    {
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->expects($this->once())
            ->method('isReadOnly')
            ->with('tax/calculation/based_on', 'websites', 'base')
            ->willReturn(false);

        $writer = $this->writer($this->savingFactory(), null, null, $settingChecker);
        $writer->write('tax/calculation/based_on', 'total', 'websites', 'base');
    }

    /**
     * PR #48 review round 4 (C1): the canonical id of a scope comes from the resolver the save uses, for an id and
     * a code alike; the default scope is 0 without asking it.
     *
     * @return void
     */
    public function testCanonicalScopeIdResolvesIdsAndCodesThroughTheSavesResolver(): void
    {
        $asked = [];
        $pool = $this->createMock(ScopeResolverPool::class);
        $pool->method('get')->willReturnCallback(function (string $type) use (&$asked): ScopeResolverInterface {
            $resolver = $this->createMock(ScopeResolverInterface::class);
            $resolver->method('getScope')->willReturnCallback(
                function ($id = null) use ($type, &$asked): AppScopeInterface {
                    $asked[] = [$type, $id];
                    $known = ['websites' => ['1' => 1, 'base' => 1], 'stores' => ['1' => 1, 'default' => 1]];
                    if (!isset($known[$type][(string) $id])) {
                        throw NoSuchEntityException::singleField('code', $id);
                    }
                    $scope = $this->createMock(AppScopeInterface::class);
                    $scope->method('getId')->willReturn($known[$type][(string) $id]);
                    return $scope;
                }
            );
            return $resolver;
        });
        $writer = $this->writer(null, null, null, null, null, $pool);

        $this->assertSame(0, $writer->canonicalScopeId('default', null));
        $this->assertSame(0, $writer->canonicalScopeId('default', 'base'));
        $this->assertSame([], $asked, 'the default scope is never resolved');
        $this->assertSame(1, $writer->canonicalScopeId('websites', '1'));
        $this->assertSame(1, $writer->canonicalScopeId('websites', 'base'));
        $this->assertSame(1, $writer->canonicalScopeId('stores', 'default'));
        $this->assertSame([['websites', '1'], ['websites', 'base'], ['stores', 'default']], $asked);
    }

    /**
     * Never throws: an unknown code or scope type, or a missing code (which the resolver would read as the current
     * store), is null and is left to the item's own error.
     *
     * @return void
     */
    public function testCanonicalScopeIdIsNullWhenTheScopeDoesNotResolve(): void
    {
        $asked = [];
        $pool = $this->scopeResolverPool($asked);
        $writer = $this->writer(null, null, null, null, null, $pool);

        $this->assertNull($writer->canonicalScopeId('websites', 'missing'));
        $this->assertNull($writer->canonicalScopeId('websites', null));
        $this->assertNull($writer->canonicalScopeId('stores', ''));
        $this->assertNull($writer->canonicalScopeId('groups', '1'));
        $this->assertSame([['websites', 'missing']], $asked);
    }

    /**
     * SettingChecker's env-variable branch uses the code verbatim, so a numeric id would probe
     * CONFIG__WEBSITES__1__… while the real lock is CONFIG__WEBSITES__BASE__… and be missed.
     *
     * @return void
     */
    public function testTheLockCheckResolvesANumericScopeCodeBeforeProbing(): void
    {
        $resolver = $this->createMock(ScopeCodeResolver::class);
        $resolver->expects($this->once())->method('resolve')->with('websites', '1')->willReturn('base');

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->expects($this->once())
            ->method('isReadOnly')
            ->with('tax/calculation/based_on', 'websites', 'base')
            ->willReturn(false);

        $writer = $this->writer($this->savingFactory(), null, null, $settingChecker, $resolver);
        $writer->write('tax/calculation/based_on', 'total', 'websites', '1');
    }

    /**
     * resolvePath() and the placeholder both ignore the code in the default scope, and resolving one
     * there would only invent a store context the write does not have.
     *
     * @return void
     */
    public function testTheLockCheckDoesNotResolveACodeInTheDefaultScope(): void
    {
        $resolver = $this->createMock(ScopeCodeResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->expects($this->once())
            ->method('isReadOnly')
            ->with('tax/calculation/based_on', 'default', null)
            ->willReturn(false);

        $writer = $this->writer($this->savingFactory(), null, null, $settingChecker, $resolver);
        $writer->write('tax/calculation/based_on', 'total', 'default', 'base');
    }

    public function testSectionForReturnsTheStructureSection(): void
    {
        $section = $this->declaredSection('tax');
        $writer = $this->writer(null, $this->structure(null, [], ['tax' => $section]));

        $this->assertSame($section, $writer->sectionFor('tax/calculation/based_on'));
    }

    public function testSectionForReturnsNullForAnUnparseablePath(): void
    {
        $this->assertNull($this->writer()->sectionFor('nonsense'));
    }

    /**
     * Structure::getElement() synthesises a blank Section for an undeclared id rather than returning
     * null; sectionFor() must not pass that placeholder off as a real section.
     *
     * @return void
     */
    public function testSectionForReturnsNullForAnUndeclaredSection(): void
    {
        $structure = $this->structure(null, [], ['no_such_section' => $this->synthesisedSection('no_such_section')]);

        $this->assertNull($this->writer(null, $structure)->sectionFor('no_such_section/group/field'));
    }

    public function testSectionForReturnsNullWhenTheElementIsNotASection(): void
    {
        $structure = $this->structure(null, [], ['tax' => $this->createMock(Group::class)]);

        $this->assertNull($this->writer(null, $structure)->sectionFor('tax/calculation/based_on'));
    }

    /**
     * The contextual ACL has to be computed for the section that will really be touched, not the one
     * the caller named.
     *
     * @return void
     */
    public function testSectionForFollowsAConfigPathRedirectToTheRealSection(): void
    {
        $requested = 'payment_us/purchaseorder/sort_order';
        $realSection = $this->declaredSection('payment');

        $structure = $this->structure(
            [$requested],
            [$requested => 'payment/purchaseorder/sort_order'],
            ['payment' => $realSection]
        );

        $this->assertSame($realSection, $this->writer(null, $structure)->sectionFor($requested));
    }

    public function testSectionForFallsBackToTheRequestedSectionWhenNoFieldIsDeclared(): void
    {
        $section = $this->declaredSection('tax');
        $structure = $this->structure(null, [], ['tax' => $section]);

        $this->assertSame($section, $this->writer(null, $structure)->sectionFor('tax/calculation/algorithm'));
    }

    /**
     * A degenerate `<config_path>` is not a redirect, so the section must stay the requested one.
     *
     * @return void
     */
    public function testSectionForIgnoresAConfigPathWithoutAnInnerSlash(): void
    {
        $section = $this->declaredSection('tax');
        $structure = $this->structure(null, ['tax/calculation/based_on' => 'nonsense'], ['tax' => $section]);

        $this->assertSame($section, $this->writer(null, $structure)->sectionFor('tax/calculation/based_on'));
    }

    public function testCurrentValueReadsTheGivenScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('tax/calculation/based_on', 'websites', 'base')
            ->willReturn('shipping');

        $writer = $this->writer(null, null, $scopeConfig);

        $this->assertSame('shipping', $writer->currentValue('tax/calculation/based_on', 'websites', 'base'));
    }

    /**
     * @dataProvider unknownScopeProvider
     * @param string $scope
     * @return void
     */
    public function testCurrentValueRejectsAnUnknownScopeWithoutReading(string $scope): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->never())->method('getValue');

        $this->expectException(LocalizedException::class);
        $this->writer(null, null, $scopeConfig)->currentValue('tax/calculation/based_on', $scope, 'base');
    }

    /**
     * Read and write must agree on the scope, or previous_value describes a different row from the
     * one the write touches.
     *
     * @return void
     */
    public function testCurrentValueRejectsANonDefaultScopeWithoutACodeWithoutReading(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->never())->method('getValue');

        $this->expectException(LocalizedException::class);
        $this->writer(null, null, $scopeConfig)->currentValue('tax/calculation/based_on', 'websites', null);
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function currentValueProvider(): array
    {
        return [
            'string' => ['total', 'total'],
            'empty string' => ['', ''],
            'zero string' => ['0', '0'],
            'integer' => [7, '7'],
            'float' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'null' => [null, null],
            'array' => [['a' => 'b'], null],
        ];
    }

    /**
     * @dataProvider currentValueProvider
     * @param mixed $stored
     * @param string|null $expected
     * @return void
     */
    public function testCurrentValueStringifiesScalarsAndDropsTheRest(mixed $stored, ?string $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $writer = $this->writer(null, null, $scopeConfig);

        $this->assertSame($expected, $writer->currentValue('tax/calculation/based_on', 'default', null));
    }
}
