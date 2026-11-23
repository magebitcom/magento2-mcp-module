<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model;

use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\OAuth\GrantDriftCache;
use Magebit\Mcp\Model\ResourceModel\Token as TokenResource;
use Magebit\Mcp\Model\ResourceModel\Token\Collection;
use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TokenRepositoryTest extends TestCase
{
    /**
     * @phpstan-var AdapterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdapterInterface&MockObject $connection;

    /**
     * @phpstan-var JobCanceller&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobCanceller&MockObject $canceller;

    /**
     * @phpstan-var TokenResource&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenResource&MockObject $resource;

    /**
     * @phpstan-var TokenFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenFactory&MockObject $factory;

    private TokenRepository $repository;

    /**
     * @phpstan-var GrantDriftCache&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private GrantDriftCache&MockObject $grantDriftCache;

    /**
     * @phpstan-var CollectionFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CollectionFactory&MockObject $collectionFactory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, int $value): string => str_replace('?', (string) $value, $text)
        );
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn ($v): string => '`' . $v . '`');
        $this->resource = $this->createMock(TokenResource::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getMainTable')->willReturn('magebit_mcp_token');
        $this->factory = $this->getMockBuilder(TokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-11-20 10:00:00');
        $this->canceller = $this->createMock(JobCanceller::class);
        $this->collectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->grantDriftCache = $this->createMock(GrantDriftCache::class);
        $this->repository = new TokenRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory,
            $dateTime,
            $this->canceller,
            $this->grantDriftCache
        );
    }

    /**
     * Review M1: every client-wide revoke path (disable, delete, rotate with revoke) cancels the client's jobs.
     *
     * @return void
     */
    public function testRevokeAllForClientCancelsTheClientsJobs(): void
    {
        $this->connection->expects(self::once())->method('update')
            ->with(
                'magebit_mcp_token',
                ['revoked_at' => '2026-11-20 10:00:00'],
                ['oauth_client_id = 4', '`revoked_at` IS NULL']
            )
            ->willReturn(2);
        $this->canceller->expects(self::once())->method('cancelForClient')->with(4)->willReturn(1);

        self::assertSame(2, $this->repository->revokeAllForClient(4));
    }

    /**
     * Jobs are cancelled even when every token of the client was already revoked or rotated away.
     *
     * @return void
     */
    public function testRevokeAllForClientCancelsJobsWhenNoTokenWasLive(): void
    {
        $this->connection->method('update')->willReturn(0);
        $this->canceller->expects(self::once())->method('cancelForClient')->with(4)->willReturn(2);

        self::assertSame(0, $this->repository->revokeAllForClient(4));
    }

    /**
     * A single-token revoke never cancels jobs here: OAuth refresh rotation goes through it, and the explicit
     * revoke paths call the canceller themselves.
     *
     * @return void
     */
    public function testSingleTokenRevokeDoesNotCancelJobs(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(9);
        $token->method('isRevoked')->willReturn(false);
        $token->expects(self::once())->method('setRevokedAt')->with('2026-11-20 10:00:00');
        $this->factory->method('create')->willReturn($token);
        $this->resource->expects(self::once())->method('save')->with($token);
        $this->canceller->expects(self::never())->method('cancelForClient');
        $this->canceller->expects(self::never())->method('cancelForRevokedToken');

        self::assertSame($token, $this->repository->revoke(9));
    }

    /**
     * Review lap 1 #1: the stand-in token for a job whose creating token was deleted is the connection's newest
     * live one; revoked rows are filtered in SQL, expired ones here.
     *
     * @return void
     */
    public function testFindNewestActiveForClientPicksTheNewestUnexpiredToken(): void
    {
        $older = $this->tokenStub(4, false);
        $newest = $this->tokenStub(9, false);
        $expired = $this->tokenStub(11, true);
        $collection = $this->createMock(Collection::class);
        $filters = [];
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use (&$filters, $collection): Collection {
                $filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('getItems')->willReturn([$older, $expired, $newest]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertSame($newest, $this->repository->findNewestActiveForClient(7, 5));
        self::assertSame([
            'oauth_client_id' => ['eq' => 5],
            'admin_user_id' => ['eq' => 7],
            'revoked_at' => ['null' => true],
        ], $filters);
    }

    /**
     * @return void
     */
    public function testFindNewestActiveForClientReturnsNullWithoutALiveToken(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn([$this->tokenStub(4, true)]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertNull($this->repository->findNewestActiveForClient(7, 5));
    }

    /**
     * PR #48 round 5 review fix: the stand-in for a revoked creating OAuth token is the connection's newest
     * unrevoked token, expired or not (an idle client refreshes only on its next call). One row, newest first,
     * revoked rows filtered in SQL, no expiry filter.
     *
     * @return void
     */
    public function testFindNewestUnrevokedForClientIgnoresExpiry(): void
    {
        $expired = $this->tokenStub(9, true);
        $collection = $this->createMock(Collection::class);
        $filters = [];
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use (&$filters, $collection): Collection {
                $filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->expects(self::once())->method('setOrder')->with('id', 'DESC')->willReturnSelf();
        $collection->expects(self::once())->method('setPageSize')->with(1)->willReturnSelf();
        $collection->method('getItems')->willReturn([$expired]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertSame($expired, $this->repository->findNewestUnrevokedForClient(7, 5));
        self::assertSame([
            'oauth_client_id' => ['eq' => 5],
            'admin_user_id' => ['eq' => 7],
            'revoked_at' => ['null' => true],
        ], $filters);
    }

    /**
     * @return void
     */
    public function testFindNewestUnrevokedForClientReturnsNullWhenEveryTokenIsRevoked(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertNull($this->repository->findNewestUnrevokedForClient(7, 5));
    }

    /**
     * Issuing a token (admin form, CLI, OAuth exchange and refresh rotation) clears the cached drift count.
     *
     * @return void
     */
    public function testSaveInvalidatesTheGrantDriftCount(): void
    {
        $token = $this->createMock(Token::class);
        $this->resource->expects(self::once())->method('save')->with($token);
        $this->grantDriftCache->expects(self::once())->method('invalidate');

        self::assertSame($token, $this->repository->save($token));
    }

    /**
     * @return void
     */
    public function testRevokeInvalidatesTheGrantDriftCount(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(9);
        $token->method('isRevoked')->willReturn(false);
        $this->factory->method('create')->willReturn($token);
        $this->grantDriftCache->expects(self::once())->method('invalidate');

        $this->repository->revoke(9);
    }

    /**
     * @return void
     */
    public function testRevokingAnAlreadyRevokedTokenKeepsTheGrantDriftCount(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(9);
        $token->method('isRevoked')->willReturn(true);
        $this->factory->method('create')->willReturn($token);
        $this->grantDriftCache->expects(self::never())->method('invalidate');

        $this->repository->revoke(9);
    }

    /**
     * @return void
     */
    public function testRevokeAllForClientInvalidatesTheGrantDriftCount(): void
    {
        $this->connection->method('update')->willReturn(2);
        $this->grantDriftCache->expects(self::once())->method('invalidate');

        $this->repository->revokeAllForClient(4);
    }

    /**
     * @return void
     */
    public function testDeleteInvalidatesTheGrantDriftCount(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(9);
        $this->factory->method('create')->willReturn($token);
        $this->resource->expects(self::once())->method('delete')->with($token);
        $this->grantDriftCache->expects(self::once())->method('invalidate');

        $this->repository->deleteById(9);
    }

    /**
     * Live tokens with a literal grant: revoked, wildcard and expired rows are filtered in SQL (review F3); the
     * PHP expiry check stays as a safety net. Rows come in id-keyset pages with only the columns grant drift reads.
     *
     * @return void
     */
    public function testActiveWithLiteralGrantPagesSkipExpiredTokensAndSelectOnlyTheNeededColumns(): void
    {
        $live = $this->tokenStub(4, false);
        $expired = $this->tokenStub(11, true);
        /** @var \ArrayObject<string, mixed> $log */
        $log = new \ArrayObject();
        $collection = $this->pageCollection([$live, $expired], $log);
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $pages = iterator_to_array($this->repository->getActiveWithLiteralGrantPages(), false);

        self::assertSame([[$live]], $pages);
        self::assertSame([
            'revoked_at' => ['null' => true],
            'scopes_json' => ['notnull' => true],
            'expires_at' => [['null' => true], ['gt' => '2026-11-20 10:00:00']],
            'id' => ['gt' => 0],
        ], $log['filters']);
        self::assertSame([[
            'id', 'admin_user_id', 'oauth_client_id', 'scopes_json', 'offered_tools_json', 'allow_writes',
            'expires_at', 'revoked_at',
        ]], $log['selected']);
        self::assertSame([['id', 'ASC']], $log['orders']);
        self::assertSame([TokenRepository::DRIFT_PAGE_SIZE], $log['pageSizes']);
        self::assertSame(1, $log['cleared']);
    }

    /**
     * 501 live tokens: a full first page of 500, then a page keyed after the last id holding the one left.
     *
     * @return void
     */
    public function testActiveWithLiteralGrantPagesWalkTheIdKeyset(): void
    {
        self::assertSame(500, TokenRepository::DRIFT_PAGE_SIZE);
        $first = [];
        for ($id = 1; $id <= 500; $id++) {
            $first[] = $this->tokenStub($id, false);
        }
        $last = $this->tokenStub(501, false);
        /** @var \ArrayObject<string, mixed> $firstLog */
        $firstLog = new \ArrayObject();
        /** @var \ArrayObject<string, mixed> $secondLog */
        $secondLog = new \ArrayObject();
        $firstCollection = $this->pageCollection($first, $firstLog);
        $secondCollection = $this->pageCollection([$last], $secondLog);
        $this->collectionFactory->expects(self::exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($firstCollection, $secondCollection);

        $pages = iterator_to_array($this->repository->getActiveWithLiteralGrantPages(), false);

        self::assertCount(2, $pages);
        self::assertSame($first, $pages[0]);
        self::assertSame([$last], $pages[1]);
        $firstFilters = $firstLog['filters'];
        $secondFilters = $secondLog['filters'];
        self::assertIsArray($firstFilters);
        self::assertIsArray($secondFilters);
        self::assertSame(['gt' => 0], $firstFilters['id'] ?? null);
        self::assertSame(['gt' => 500], $secondFilters['id'] ?? null);
        self::assertSame(1, $firstLog['cleared']);
        self::assertSame(1, $secondLog['cleared']);
    }

    /**
     * A collection mock serving one page and recording the query it was given in `$log` (filters, selected,
     * orders, pageSizes, cleared).
     *
     * @param list<Token&MockObject> $items
     * @param \ArrayObject<string, mixed> $log
     * @return Collection&MockObject
     */
    private function pageCollection(array $items, \ArrayObject $log): Collection&MockObject
    {
        $log['filters'] = [];
        $log['selected'] = [];
        $log['orders'] = [];
        $log['pageSizes'] = [];
        $log['cleared'] = 0;
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            static function (string $field, array $condition) use ($log, $collection): Collection {
                $log['filters'] = [...(array) $log['filters'], $field => $condition];
                return $collection;
            }
        );
        $collection->method('addFieldToSelect')->willReturnCallback(
            static function ($field) use ($log, $collection): Collection {
                $log['selected'] = [...(array) $log['selected'], $field];
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnCallback(
            static function (string $field, string $direction) use ($log, $collection): Collection {
                $log['orders'] = [...(array) $log['orders'], [$field, $direction]];
                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnCallback(
            static function (int $size) use ($log, $collection): Collection {
                $log['pageSizes'] = [...(array) $log['pageSizes'], $size];
                return $collection;
            }
        );
        $collection->method('getItems')->willReturn($items);
        $collection->method('clear')->willReturnCallback(
            static function () use ($log, $collection): Collection {
                $log['cleared'] = (int) $log['cleared'] + 1;
                return $collection;
            }
        );
        return $collection;
    }

    /**
     * @param int $id
     * @param bool $expired
     * @return Token&MockObject
     */
    private function tokenStub(int $id, bool $expired): Token&MockObject
    {
        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn($id);
        $token->method('isExpired')->willReturn($expired);
        return $token;
    }
}
