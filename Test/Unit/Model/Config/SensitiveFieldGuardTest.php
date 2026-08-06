<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use PHPUnit\Framework\TestCase;

class SensitiveFieldGuardTest extends TestCase
{
    /**
     * @param mixed $element
     * @return SensitiveFieldGuard
     */
    private function guardReturning(mixed $element): SensitiveFieldGuard
    {
        $structure = $this->createMock(Structure::class);
        $structure->method('getElementByConfigPath')->willReturn($element);

        return new SensitiveFieldGuard($structure);
    }

    public function testAnOrdinaryFieldIsNotRejected(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('text');
        $field->method('getAttribute')->willReturn(null);

        $this->assertNull($this->guardReturning($field)->rejectionFor('tax/calculation/based_on'));
    }

    public function testPasswordFieldTypeIsRejected(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('password');
        $field->method('getAttribute')->willReturn(null);

        $this->assertSame(
            'field_type_sensitive',
            $this->guardReturning($field)->rejectionFor('some/group/field')
        );
    }

    public function testObscureFieldTypeIsRejected(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('obscure');
        $field->method('getAttribute')->willReturn(null);

        $this->assertSame(
            'field_type_sensitive',
            $this->guardReturning($field)->rejectionFor('some/group/field')
        );
    }

    public function testEncryptedBackendModelIsRejected(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn('text');
        $field->method('getAttribute')->willReturn(\Magento\Config\Model\Config\Backend\Encrypted::class);

        $this->assertSame(
            'encrypted_backend_model',
            $this->guardReturning($field)->rejectionFor('some/group/field')
        );
    }

    public function testNonFieldElementIsRejected(): void
    {
        $this->assertSame(
            'non_field_path',
            $this->guardReturning($this->createMock(Group::class))->rejectionFor('tax/calculation')
        );
    }

    public function testKeywordBlockedPathIsRejectedEvenWithNoStructureEntry(): void
    {
        $this->assertSame(
            'path_keyword_blocked',
            $this->guardReturning(null)->rejectionFor('custom/group/api_key')
        );
    }

    public function testUnknownButHarmlessPathIsNotRejected(): void
    {
        $this->assertNull($this->guardReturning(null)->rejectionFor('custom/group/threshold'));
    }
}
