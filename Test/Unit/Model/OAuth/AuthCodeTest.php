<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\Data\OAuth\AuthCodeInterface;
use Magebit\Mcp\Model\OAuth\AuthCode;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class AuthCodeTest extends TestCase
{
    /**
     * @return void
     */
    public function testOfferedToolsRoundTripAsJson(): void
    {
        $code = $this->code();
        $code->setOfferedTools(['catalog.product.get', 'sales.order.get', 'catalog.product.get']);

        self::assertSame(
            '["catalog.product.get","sales.order.get"]',
            $code->getData(AuthCodeInterface::OFFERED_TOOLS_JSON)
        );
        self::assertSame(['catalog.product.get', 'sales.order.get'], $code->getOfferedTools());
    }

    /**
     * An empty record is kept as `[]`, not as "no record": every tool offered later is new for it.
     *
     * @return void
     */
    public function testEmptyOfferedToolsIsARecord(): void
    {
        $code = $this->code();
        $code->setOfferedTools([]);

        self::assertSame('[]', $code->getData(AuthCodeInterface::OFFERED_TOOLS_JSON));
        self::assertSame([], $code->getOfferedTools());
    }

    /**
     * @return void
     */
    public function testNoRecordIsNull(): void
    {
        $code = $this->code();
        self::assertNull($code->getOfferedTools());

        $code->setOfferedTools(null);
        self::assertNull($code->getData(AuthCodeInterface::OFFERED_TOOLS_JSON));
        self::assertNull($code->getOfferedTools());

        $code->setData(AuthCodeInterface::OFFERED_TOOLS_JSON, 'not json');
        self::assertNull($code->getOfferedTools());
    }

    /**
     * @return AuthCode
     */
    private function code(): AuthCode
    {
        $code = (new ObjectManager($this))->getObject(AuthCode::class);
        self::assertInstanceOf(AuthCode::class, $code);
        return $code;
    }
}
