<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\OAuth\AuthorizeHandoffStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthorizeHandoffStorageTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private TokenHasher&MockObject $hasher;
    private DateTime&MockObject $dateTime;
    private LoggerInterface&MockObject $logger;
    private AuthorizeHandoffStorage $storage;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->hasher = $this->createMock(TokenHasher::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $name): string => $name);

        $this->storage = new AuthorizeHandoffStorage(
            $this->resourceConnection,
            $this->hasher,
            $this->dateTime,
            $this->logger
        );
    }

    public function testStoreInsertsHashAndJsonAndExpiry(): void
    {
        $this->hasher->method('hash')->with('plaintext-nonce')->willReturn('h:plaintext-nonce');
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 12:00:00');

        $this->connection->expects(self::once())->method('insert')
            ->with(
                'magebit_mcp_oauth_authorize_handoff',
                self::callback(function (array $data): bool {
                    self::assertSame('h:plaintext-nonce', $data['nonce_hash']);
                    self::assertSame('2026-04-28 12:00:00', $data['expires_at']);
                    self::assertJson($data['params_json']);
                    self::assertSame(
                        ['client_id' => 'abc', 'state' => 'xyz'],
                        json_decode($data['params_json'], true)
                    );
                    return true;
                })
            );

        $this->storage->store('plaintext-nonce', ['client_id' => 'abc', 'state' => 'xyz']);
    }

    public function testPeekReturnsParamsAndKeepsRow(): void
    {
        $this->hasher->method('hash')->willReturn('h:nonce');
        // `now()` is the only call on dateTime in peek path — return a value safely past expiry.
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 11:59:00');

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'id' => 7,
            'params_json' => '{"client_id":"abc"}',
            'expires_at' => '2026-04-28 12:00:00',
        ]);
        // peek() must NOT delete.
        $this->connection->expects(self::never())->method('delete');

        self::assertSame(['client_id' => 'abc'], $this->storage->peek('nonce'));
    }

    public function testConsumeReturnsParamsAndDeletesRow(): void
    {
        $this->hasher->method('hash')->willReturn('h:nonce');
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 11:59:00');

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'id' => 7,
            'params_json' => '{"client_id":"abc"}',
            'expires_at' => '2026-04-28 12:00:00',
        ]);
        $this->connection->expects(self::once())->method('delete')
            ->with('magebit_mcp_oauth_authorize_handoff', ['id = ?' => 7])
            ->willReturn(1);

        self::assertSame(['client_id' => 'abc'], $this->storage->consume('nonce'));
    }

    public function testConsumeReturnsNullForUnknownNonce(): void
    {
        $this->hasher->method('hash')->willReturn('h:nonce');

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);
        // No row, no delete.
        $this->connection->expects(self::never())->method('delete');

        self::assertNull($this->storage->consume('nonce'));
    }

    public function testConsumeDeletesButReturnsNullForExpiredRow(): void
    {
        $this->hasher->method('hash')->willReturn('h:nonce');
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 12:01:00');

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'id' => 7,
            'params_json' => '{"client_id":"abc"}',
            'expires_at' => '2026-04-28 12:00:00',
        ]);
        // Even though the row is expired, we still delete it on the way out
        // (don't leave stale rows lying around once we've seen them).
        $this->connection->expects(self::once())->method('delete');

        self::assertNull($this->storage->consume('nonce'));
    }

    public function testPurgeExpiredRunsSingleDelete(): void
    {
        $this->dateTime->method('gmtDate')->willReturn('2026-04-28 12:00:00');
        $this->connection->expects(self::once())->method('delete')
            ->with('magebit_mcp_oauth_authorize_handoff', ['expires_at < ?' => '2026-04-28 12:00:00'])
            ->willReturn(3);

        self::assertSame(3, $this->storage->purgeExpired());
    }
}
