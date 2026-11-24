<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Ui\Component\Listing\Column;

use Magebit\Mcp\Ui\Component\Listing\Column\TokenActions;
use Magebit\Mcp\Ui\Component\Listing\Column\TokenStatus;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Runs the token listing's status and actions columns in listing order over
 * the same rows, so a status column that overwrites a raw field the actions
 * column reads (MHP-1159) fails here.
 */
class TokenStatusActionsTest extends TestCase
{
    private const LISTING_XML = __DIR__
        . '/../../../../../../view/adminhtml/ui_component/magebit_mcp_token_listing.xml';

    /**
     * Raw row fields {@see TokenActions::prepareDataSource()} reads.
     */
    private const ACTIONS_RAW_FIELDS = ['id', 'name', 'revoked_at'];

    private const NOW = '2026-11-23 12:00:00';

    public function testActiveTokenGetsRevokeAndDelete(): void
    {
        $actions = $this->actionsFor(['id' => 1, 'name' => 'A', 'revoked_at' => null, 'expires_at' => null]);

        self::assertArrayHasKey('revoke', $actions);
        self::assertArrayHasKey('delete', $actions);
        $revoke = $actions['revoke'];
        self::assertIsArray($revoke);
        self::assertSame('magebit_mcp/token/revoke/id/1', $revoke['href']);
        self::assertTrue($revoke['post']);
        self::assertArrayHasKey('confirm', $revoke);
        self::assertNotEmpty($revoke['confirm']);
    }

    public function testRevokedTokenGetsDeleteOnly(): void
    {
        $actions = $this->actionsFor([
            'id' => 2,
            'name' => 'B',
            'revoked_at' => '2026-11-01 10:00:00',
            'expires_at' => null,
        ]);

        self::assertArrayHasKey('delete', $actions);
        self::assertArrayNotHasKey('revoke', $actions);
    }

    public function testExpiredNotRevokedTokenGetsRevoke(): void
    {
        $actions = $this->actionsFor([
            'id' => 3,
            'name' => 'C',
            'revoked_at' => null,
            'expires_at' => '2026-11-01 00:00:00',
        ]);

        self::assertArrayHasKey('revoke', $actions);
        self::assertArrayHasKey('delete', $actions);
    }

    public function testStatusLabelsUnchanged(): void
    {
        $items = $this->runColumns([
            ['id' => 1, 'name' => 'A', 'revoked_at' => null, 'expires_at' => null],
            ['id' => 2, 'name' => 'B', 'revoked_at' => '2026-11-01 10:00:00', 'expires_at' => null],
            ['id' => 3, 'name' => 'C', 'revoked_at' => null, 'expires_at' => '2026-11-01 00:00:00'],
        ]);
        $statusField = $this->statusColumnName();

        self::assertSame(
            '<span class="grid-severity-notice"><span>Active</span></span>',
            $items[0][$statusField]
        );
        self::assertSame(
            '<span class="grid-severity-critical"><span>Revoked</span></span>',
            $items[1][$statusField]
        );
        self::assertSame(
            '<span class="grid-severity-minor"><span>Expired</span></span>',
            $items[2][$statusField]
        );
    }

    public function testStatusColumnDoesNotOverwriteAFieldTheActionsColumnReads(): void
    {
        self::assertNotContains($this->statusColumnName(), self::ACTIONS_RAW_FIELDS);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<mixed>
     */
    private function actionsFor(array $row): array
    {
        $actions = $this->runColumns([$row])[0]['actions'] ?? null;
        self::assertIsArray($actions);

        return $actions;
    }

    /**
     * Runs TokenStatus, then TokenActions, over one data source, as the listing does.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function runColumns(array $rows): array
    {
        $context = $this->createMock(ContextInterface::class);
        $factory = $this->createMock(UiComponentFactory::class);
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '')
        );
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value)
        );
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn((int) strtotime(self::NOW . ' UTC'));
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (?string $route = null, ?array $params = null): string {
                $id = $params['id'] ?? null;

                return (string) $route . '/id/' . (is_scalar($id) ? (string) $id : '');
            }
        );

        $status = new TokenStatus($context, $factory, $escaper, $dateTime, [], ['name' => $this->statusColumnName()]);
        $actions = new TokenActions($context, $factory, $url, $escaper, [], ['name' => 'actions']);

        /** @var array{data: array{items: array<int, array<string, mixed>>}} $afterStatus */
        $afterStatus = $status->prepareDataSource(['data' => ['items' => $rows]]);
        /** @var array{data: array{items: array<int, array<string, mixed>>}} $afterActions */
        $afterActions = $actions->prepareDataSource($afterStatus);

        return $afterActions['data']['items'];
    }

    /**
     * The `name` of the listing column rendered by TokenStatus.
     */
    private function statusColumnName(): string
    {
        $xml = simplexml_load_file(self::LISTING_XML);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $matches = $xml->xpath(sprintf('//column[@class="%s"]', TokenStatus::class));
        self::assertIsArray($matches);
        self::assertCount(1, $matches);

        return (string) $matches[0]['name'];
    }
}
