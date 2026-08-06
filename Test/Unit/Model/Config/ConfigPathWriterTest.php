<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magento\Config\Model\Config as AdminConfig;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigPathWriterTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $expectedData Asserted against the factory argument.
     * @return array{0: ConfigPathWriter, 1: AdminConfig&MockObject}
     */
    private function writerExpecting(?array $expectedData): array
    {
        $adminConfig = $this->createMock(AdminConfig::class);

        $factory = $this->createMock(ConfigFactory::class);
        $create = $factory->expects($this->once())->method('create');
        if ($expectedData !== null) {
            $create->with(['data' => $expectedData]);
        }
        $create->willReturn($adminConfig);

        $writer = new ConfigPathWriter(
            $factory,
            $this->createMock(Structure::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        return [$writer, $adminConfig];
    }

    /**
     * @param Structure|null $structure
     * @param ScopeConfigInterface|null $scopeConfig
     * @return ConfigPathWriter
     */
    private function writerRefusingToCreate(
        ?Structure $structure = null,
        ?ScopeConfigInterface $scopeConfig = null
    ): ConfigPathWriter {
        $factory = $this->createMock(ConfigFactory::class);
        $factory->expects($this->never())->method('create');

        return new ConfigPathWriter(
            $factory,
            $structure ?? $this->createMock(Structure::class),
            $scopeConfig ?? $this->createMock(ScopeConfigInterface::class)
        );
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

    public function testTheSingularWebsiteScopeNameIsAcceptedAsWebsiteScope(): void
    {
        [$writer] = $this->writerExpecting([
            'section' => 'tax',
            'website' => 'base',
            'store' => '',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);

        $writer->write('tax/calculation/based_on', 'total', 'website', 'base');
    }

    public function testTheSingularStoreScopeNameIsAcceptedAsStoreScope(): void
    {
        [$writer] = $this->writerExpecting([
            'section' => 'tax',
            'website' => '',
            'store' => 'default',
            'groups' => ['calculation' => ['fields' => ['based_on' => ['value' => 'total']]]],
        ]);

        $writer->write('tax/calculation/based_on', 'total', 'store', 'default');
    }

    /**
     * An unrecognised scope must not quietly degrade to a default-scope write, which would set the
     * value for every store instead of the one asked for.
     *
     * @return void
     */
    public function testAnUnknownScopeNameIsRejectedRatherThanTreatedAsDefault(): void
    {
        $writer = $this->writerRefusingToCreate();

        $this->expectException(LocalizedException::class);
        $writer->write('tax/calculation/based_on', 'total', 'group', 'base');
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

    public function testSectionForReturnsTheStructureSection(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn([
            'id' => 'tax',
            'label' => 'Tax',
            'resource' => 'Magento_Tax::config_tax',
            '_elementType' => 'section',
        ]);

        $structure = $this->createMock(Structure::class);
        $structure->method('getElement')->with('tax')->willReturn($section);

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $structure,
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertSame($section, $writer->sectionFor('tax/calculation/based_on'));
    }

    public function testSectionForReturnsNullForAnUnparseablePath(): void
    {
        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $this->createMock(Structure::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertNull($writer->sectionFor('nonsense'));
    }

    /**
     * Structure::getElement() synthesises a blank Section for an undeclared id rather than
     * returning null; sectionFor() must not pass that placeholder off as a real section.
     *
     * @return void
     */
    public function testSectionForReturnsNullForAnUndeclaredSection(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn([
            'id' => 'no_such_section',
            'path' => '',
            '_elementType' => 'section',
        ]);

        $structure = $this->createMock(Structure::class);
        $structure->method('getElement')->with('no_such_section')->willReturn($section);

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $structure,
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertNull($writer->sectionFor('no_such_section/group/field'));
    }

    public function testSectionForReturnsNullWhenTheElementIsNotASection(): void
    {
        $structure = $this->createMock(Structure::class);
        $structure->method('getElement')->willReturn($this->createMock(Field::class));

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $structure,
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertNull($writer->sectionFor('tax/calculation/based_on'));
    }

    public function testSectionForLooksUpOnlyTheFirstSegment(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn(['id' => 'tax', 'children' => [], '_elementType' => 'section']);

        $structure = $this->createMock(Structure::class);
        $structure->expects($this->once())->method('getElement')->with('tax')->willReturn($section);

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $structure,
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertSame($section, $writer->sectionFor('tax/outer/inner/based_on'));
    }

    public function testCurrentValueReadsTheGivenScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('tax/calculation/based_on', 'websites', 'base')
            ->willReturn('shipping');

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $this->createMock(Structure::class),
            $scopeConfig
        );

        $this->assertSame('shipping', $writer->currentValue('tax/calculation/based_on', 'websites', 'base'));
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
            'object' => [new \stdClass(), null],
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

        $writer = new ConfigPathWriter(
            $this->createMock(ConfigFactory::class),
            $this->createMock(Structure::class),
            $scopeConfig
        );

        $this->assertSame($expected, $writer->currentValue('tax/calculation/based_on', 'default', null));
    }
}
