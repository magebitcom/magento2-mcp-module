<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Ui\Component\Listing\Column;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\OAuth\GrantDrift;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Ui\Component\Listing\Column\TokenScopes;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TokenScopesTest extends TestCase
{
    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    /**
     * @phpstan-var GrantDrift&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private GrantDrift&MockObject $grantDrift;

    private TokenScopes $column;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);

        $this->tokenRepository = $this->createMock(TokenRepository::class);
        $this->grantDrift = $this->createMock(GrantDrift::class);
        $this->column = new TokenScopes(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            $escaper,
            $this->tokenRepository,
            $this->grantDrift,
            [],
            ['name' => 'scopes_json']
        );
    }

    /**
     * @return void
     */
    public function testWildcardGrantFollowsTheRole(): void
    {
        $this->givenTokens([5 => null]);

        $cell = $this->render([['id' => '5', 'scopes_json' => null]])[0];

        self::assertStringContainsString('All tools (follows role)', $cell);
        self::assertStringNotContainsString('missing', $cell);
    }

    /**
     * @return void
     */
    public function testLiteralGrantWithoutDriftShowsTheCount(): void
    {
        $this->givenTokens([5 => ['granted' => 2, 'missing' => []]]);

        $cell = $this->render([['id' => '5', 'scopes_json' => '["sales.order.get","catalog.product.get"]']])[0];

        self::assertStringContainsString('2 tools', $cell);
        self::assertStringContainsString('sales.order.get', $cell);
        self::assertStringNotContainsString('missing', $cell);
    }

    /**
     * @return void
     */
    public function testDriftShowsMissingCountAndWireNamesInTheTitle(): void
    {
        $this->givenTokens([5 => ['granted' => 1, 'missing' => ['catalog.product.get', 'cms.page.get']]]);

        $cell = $this->render([['id' => '5', 'scopes_json' => '["sales.order.get"]']])[0];

        self::assertStringContainsString('1 tool, 2 missing', $cell);
        self::assertMatchesRegularExpression('/title="[^"]*catalog_product_get[^"]*cms_page_get[^"]*"/', $cell);
        self::assertStringContainsString('Re-authorise to pick up new tools', $cell);
    }

    /**
     * Revoked and expired rows get null from GrantDrift: plain count, no drift.
     *
     * @return void
     */
    public function testRowWithoutDriftDataShowsThePlainCount(): void
    {
        $this->givenTokens([5 => null]);

        $cell = $this->render([['id' => '5', 'scopes_json' => '["sales.order.get","catalog.product.get"]']])[0];

        self::assertStringContainsString('2 tools', $cell);
        self::assertStringNotContainsString('missing', $cell);
    }

    /**
     * @return void
     */
    public function testNamesCountsAndTitleAreEscaped(): void
    {
        $this->givenTokens([5 => ['granted' => 1, 'missing' => ['x."><script>alert(1)</script>']]]);

        $cell = $this->render([['id' => '5', 'scopes_json' => '["a.<script>b</script>\'"]']])[0];

        self::assertStringNotContainsString('<script>', $cell);
        self::assertStringContainsString('a.&lt;script&gt;b&lt;/script&gt;&#039;', $cell);
        self::assertStringContainsString('x_&quot;&gt;&lt;script&gt;', $cell);
    }

    /**
     * One token query for the whole page, not one per row.
     *
     * @return void
     */
    public function testTokensAreLoadedOncePerPage(): void
    {
        $this->givenTokens([5 => null, 6 => ['granted' => 1, 'missing' => []]], [5, 6]);

        $cells = $this->render([
            ['id' => '5', 'scopes_json' => null],
            ['id' => '6', 'scopes_json' => '["sales.order.get"]'],
        ]);

        self::assertCount(2, $cells);
        self::assertStringContainsString('1 tool', $cells[1]);
    }

    /**
     * @param array<int, array{granted: int, missing: list<string>}|null> $driftById
     * @param array<int, int>|null $expectedIds
     * @return void
     */
    private function givenTokens(array $driftById, ?array $expectedIds = null): void
    {
        $tokens = [];
        $drift = [];
        foreach ($driftById as $id => $result) {
            $token = $this->createMock(Token::class);
            $token->method('getId')->willReturn($id);
            $tokens[$id] = $token;
            $drift[spl_object_id($token)] = $result;
        }
        $listByIds = $this->tokenRepository->expects(self::once())->method('listByIds');
        if ($expectedIds !== null) {
            $listByIds->with($expectedIds);
        }
        $listByIds->willReturn($tokens);
        $this->grantDrift->method('forToken')->willReturnCallback(
            static fn (TokenInterface $token): ?array => $drift[spl_object_id($token)] ?? null
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function render(array $items): array
    {
        /** @var array{data: array{items: array<int, array<string, mixed>>}} $result */
        $result = $this->column->prepareDataSource(['data' => ['items' => $items]]);
        $cells = [];
        foreach ($result['data']['items'] as $item) {
            $cells[] = is_string($item['scopes_json']) ? $item['scopes_json'] : '';
        }
        return $cells;
    }
}
