<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use PHPUnit\Framework\MockObject\MockObject;
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

    /**
     * @param string $type
     * @param string|null $backendModel
     * @return Field&MockObject
     */
    private function field(string $type, ?string $backendModel = null): Field&MockObject
    {
        $field = $this->createMock(Field::class);
        $field->method('getType')->willReturn($type);
        $field->method('getAttribute')->with('backend_model')->willReturn($backendModel);

        return $field;
    }

    public function testAnOrdinaryFieldIsNotRejected(): void
    {
        $guard = $this->guardReturning($this->field('text'));

        $this->assertNull($guard->rejectionFor('tax/calculation/based_on'));
    }

    public function testPasswordFieldTypeIsRejected(): void
    {
        $this->assertSame(
            'field_type_sensitive',
            $this->guardReturning($this->field('password'))->rejectionFor('some/group/field')
        );
    }

    public function testObscureFieldTypeIsRejected(): void
    {
        $this->assertSame(
            'field_type_sensitive',
            $this->guardReturning($this->field('obscure'))->rejectionFor('some/group/field')
        );
    }

    public function testFieldTypeMatchIsCaseInsensitive(): void
    {
        $this->assertSame(
            'field_type_sensitive',
            $this->guardReturning($this->field('Password'))->rejectionFor('some/group/field')
        );
    }

    public function testEncryptedBackendModelIsRejected(): void
    {
        $this->assertSame(
            'encrypted_backend_model',
            $this->guardReturning($this->field('text', Encrypted::class))->rejectionFor('some/group/field')
        );
    }

    public function testSubclassOfEncryptedBackendModelIsRejected(): void
    {
        $this->assertSame(
            'encrypted_backend_model',
            $this->guardReturning($this->field('text', EncryptedBackendStub::class))->rejectionFor('some/group/field')
        );
    }

    public function testLeadingBackslashEncryptedBackendModelIsRejected(): void
    {
        $field = $this->field('text', '\\' . Encrypted::class);

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

    public function testKeywordBlockedPathIsRejectedForAnInnocuousLookingKnownField(): void
    {
        // A `type="text"` field with no backend model still gets blocked on its
        // name — the field checks narrow the blocklist, they do not replace it.
        $this->assertSame(
            'path_keyword_blocked',
            $this->guardReturning($this->field('text'))->rejectionFor('custom/group/api_key')
        );
    }

    public function testPathKeywordMatchIsCaseInsensitive(): void
    {
        $this->assertSame(
            'path_keyword_blocked',
            $this->guardReturning(null)->rejectionFor('Custom/Group/API_KEY')
        );
    }

    public function testFieldLevelRejectionOutranksThePathKeyword(): void
    {
        $field = $this->field('text', Encrypted::class);

        $this->assertSame(
            'encrypted_backend_model',
            $this->guardReturning($field)->rejectionFor('payment/braintree/sandbox_private_key')
        );
    }

    public function testUnknownButHarmlessPathIsNotRejected(): void
    {
        $this->assertNull($this->guardReturning(null)->rejectionFor('custom/group/threshold'));
    }
}
