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
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
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
     * @return ConfigPathWriter
     */
    private function writer(
        ?ConfigFactory $factory = null,
        ?Structure $structure = null,
        ?ScopeConfigInterface $scopeConfig = null,
        ?SettingChecker $settingChecker = null,
        ?ScopeCodeResolver $scopeCodeResolver = null
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
            new ConfigPathFormat()
        );
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
