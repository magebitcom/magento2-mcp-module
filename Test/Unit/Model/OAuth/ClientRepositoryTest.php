<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientFactory;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\ResourceModel\Client as ClientResource;
use Magebit\Mcp\Model\OAuth\ResourceModel\Client\CollectionFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientRepositoryTest extends TestCase
{
    /**
     * @phpstan-var ClientFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientFactory&MockObject $factory;

    /**
     * @phpstan-var ClientResource&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientResource&MockObject $resource;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    private ClientRepository $repository;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->factory = $this->createMock(ClientFactory::class);
        $this->resource = $this->createMock(ClientResource::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);

        $this->repository = new ClientRepository(
            $this->factory,
            $this->resource,
            $this->createMock(CollectionFactory::class),
            $this->tokenRepository
        );
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    public function testDeleteByIdRevokesTokensBeforeDeleting(): void
    {
        $this->stubClient(7);
        $calls = [];
        $this->tokenRepository->expects(self::once())->method('revokeAllForClient')->with(7)
            ->willReturnCallback(static function () use (&$calls): int {
                $calls[] = 'revoke';
                return 2;
            });
        $this->resource->expects(self::once())->method('save')
            ->willReturnCallback(function () use (&$calls): ClientResource {
                $calls[] = 'disable';
                return $this->resource;
            });
        $this->resource->expects(self::once())->method('delete')
            ->willReturnCallback(function () use (&$calls): ClientResource {
                $calls[] = 'delete';
                return $this->resource;
            });

        self::assertSame(2, $this->repository->deleteById(7));
        self::assertSame(['disable', 'revoke', 'delete'], $calls);
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    public function testDeleteByIdDoesNotSaveAnAlreadyDisabledClient(): void
    {
        $this->stubClient(7, true);
        $this->tokenRepository->expects(self::once())->method('revokeAllForClient')->willReturn(0);
        $this->resource->expects(self::never())->method('save');
        $this->resource->expects(self::once())->method('delete');

        self::assertSame(0, $this->repository->deleteById(7));
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    public function testDeleteByIdWithKeepTokensDoesNotRevoke(): void
    {
        $this->stubClient(7);
        $this->tokenRepository->expects(self::never())->method('revokeAllForClient');
        $this->resource->expects(self::never())->method('save');
        $this->resource->expects(self::once())->method('delete');

        self::assertSame(0, $this->repository->deleteById(7, true));
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    public function testUnknownIdThrowsBeforeRevoking(): void
    {
        $this->stubClient(null);
        $this->tokenRepository->expects(self::never())->method('revokeAllForClient');
        $this->resource->expects(self::never())->method('delete');

        $this->expectException(NoSuchEntityException::class);

        $this->repository->deleteById(7);
    }

    /**
     * @param int|null $id
     * @param bool $disabled
     * @return void
     */
    private function stubClient(?int $id, bool $disabled = false): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn($id);
        $client->method('isDisabled')->willReturn($disabled);
        $client->method('setDisabled')->willReturnSelf();
        $this->factory->method('create')->willReturn($client);
    }
}
