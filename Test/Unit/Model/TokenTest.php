<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Token;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    /**
     * @return void
     */
    public function testOfferedToolsRoundTripAsJson(): void
    {
        $token = $this->token();
        $token->setOfferedTools(['catalog.product.get', 'sales.order.get', 'catalog.product.get']);

        self::assertSame(
            '["catalog.product.get","sales.order.get"]',
            $token->getData(TokenInterface::OFFERED_TOOLS_JSON)
        );
        self::assertSame(['catalog.product.get', 'sales.order.get'], $token->getOfferedTools());
    }

    /**
     * An empty record is kept as `[]`, not as "no record": every tool offered later is new for it.
     *
     * @return void
     */
    public function testEmptyOfferedToolsIsARecord(): void
    {
        $token = $this->token();
        $token->setOfferedTools([]);

        self::assertSame('[]', $token->getData(TokenInterface::OFFERED_TOOLS_JSON));
        self::assertSame([], $token->getOfferedTools());
    }

    /**
     * @return void
     */
    public function testNoRecordIsNull(): void
    {
        $token = $this->token();
        self::assertNull($token->getOfferedTools());

        $token->setOfferedTools(null);
        self::assertNull($token->getData(TokenInterface::OFFERED_TOOLS_JSON));
        self::assertNull($token->getOfferedTools());

        $token->setData(TokenInterface::OFFERED_TOOLS_JSON, 'not json');
        self::assertNull($token->getOfferedTools());
    }

    /**
     * @return Token
     */
    private function token(): Token
    {
        $token = (new ObjectManager($this))->getObject(Token::class);
        self::assertInstanceOf(Token::class, $token);
        return $token;
    }
}
