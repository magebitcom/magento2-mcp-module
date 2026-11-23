<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\ConsentOfferedTools;
use Magebit\Mcp\Model\OAuth\GrantDrift;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GrantDriftTest extends TestCase
{
    /**
     * @phpstan-var ConsentOfferedTools&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ConsentOfferedTools&MockObject $consentOfferedTools;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $adminUserLookup;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    /**
     * @phpstan-var ClientRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientRepository&MockObject $clientRepository;

    /**
     * @phpstan-var ToolRegistryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolRegistryInterface&MockObject $toolRegistry;

    private GrantDrift $grantDrift;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->consentOfferedTools = $this->createMock(ConsentOfferedTools::class);
        $this->adminUserLookup = $this->createMock(AdminUserLookup::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $this->toolRegistry->method('all')->willReturn([
            'catalog.product.get' => $this->tool(WriteMode::READ),
            'catalog.product.update' => $this->tool(WriteMode::WRITE),
            'sales.order.get' => $this->tool(WriteMode::READ),
            'cms.page.get' => $this->tool(WriteMode::READ),
            'cms.page.save' => $this->tool(WriteMode::WRITE),
        ]);
        $this->grantDrift = new GrantDrift(
            $this->consentOfferedTools,
            new JobToolGate($this->toolRegistry, $this->createMock(AclChecker::class)),
            $this->adminUserLookup,
            $this->tokenRepository,
            $this->clientRepository,
            $this->toolRegistry
        );
    }

    /**
     * @return void
     */
    public function testWildcardGrantHasNoDrift(): void
    {
        $this->consentOfferedTools->expects(self::never())->method('forAdmin');

        self::assertNull($this->grantDrift->forToken($this->token(null)));
    }

    /**
     * @return void
     */
    public function testLiteralGrantCoveringEveryOfferedToolHasNoMissingTools(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);

        self::assertSame(
            ['granted' => 2, 'missing' => []],
            $this->grantDrift->forToken($this->token(['sales.order.get', 'catalog.product.get']))
        );
    }

    /**
     * @return void
     */
    public function testMissingToolsAreCanonicalAndSorted(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn([
            'system.store.list',
            'catalog.product.get',
            'sales.order.get',
            'cms.page.get',
        ]);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get', 'cms.page.get', 'system.store.list']],
            $this->grantDrift->forToken($this->token(['sales.order.get']))
        );
    }

    /**
     * Job tools are computed from the grant, never granted literally, so they never count as missing.
     *
     * @return void
     */
    public function testJobToolsAreNeverMissing(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn([
            'system.job.get',
            'system.job.list',
            'sales.order.get',
        ]);

        self::assertSame(
            ['granted' => 1, 'missing' => []],
            $this->grantDrift->forToken($this->token(['sales.order.get']))
        );
    }

    /**
     * @return void
     */
    public function testRevokedTokenHasNoDrift(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get']);

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'], revoked: true)));
    }

    /**
     * @return void
     */
    public function testExpiredTokenHasNoDrift(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get']);

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'], expired: true)));
    }

    /**
     * @return void
     */
    public function testMissingAdminHasNoDrift(): void
    {
        $this->adminUserLookup->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('user_id', 7));
        $this->consentOfferedTools->expects(self::never())->method('forAdmin');

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'])));
    }

    /**
     * @return void
     */
    public function testInactiveAdminHasNoDrift(): void
    {
        $this->givenAdmin(7, false);
        $this->consentOfferedTools->expects(self::never())->method('forAdmin');

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'])));
    }

    /**
     * One consent-tree build per admin, however many of that admin's tokens are checked.
     *
     * @return void
     */
    public function testOfferedToolsAreMemoisedPerAdmin(): void
    {
        $this->givenAdmin(7, true, once: true);
        $this->consentOfferedTools->expects(self::once())->method('forAdmin')
            ->willReturn(['catalog.product.get', 'sales.order.get']);

        $first = $this->grantDrift->forToken($this->token(['sales.order.get']));
        $second = $this->grantDrift->forToken($this->token(['catalog.product.get']));

        self::assertSame(['catalog.product.get'], $first['missing'] ?? null);
        self::assertSame(['sales.order.get'], $second['missing'] ?? null);
    }

    /**
     * @return void
     */
    public function testCountsActiveLiteralTokensWithMissingTools(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);
        $this->tokenRepository->expects(self::once())->method('getActiveWithLiteralGrantPages')->willReturn(
            $this->pages([[
                $this->tokenModel(['sales.order.get']),
                $this->tokenModel(['sales.order.get', 'catalog.product.get']),
                $this->tokenModel(['catalog.product.get']),
            ]])
        );

        self::assertSame(2, $this->grantDrift->countDriftingActiveTokens());
    }

    /**
     * Review M1: tokens arrive in pages; the count matches a single pass and the per-admin memo spans pages.
     *
     * @return void
     */
    public function testCountsAcrossPagesLikeASinglePass(): void
    {
        $this->givenAdmin(7, true, once: true);
        $this->consentOfferedTools->expects(self::once())->method('forAdmin')
            ->willReturn(['catalog.product.get', 'sales.order.get']);
        $first = [];
        for ($i = 0; $i < 500; $i++) {
            $first[] = $this->tokenModel($i % 2 === 0 ? ['sales.order.get'] : ['sales.order.get', 'catalog.product.get']);
        }
        $this->tokenRepository->expects(self::once())->method('getActiveWithLiteralGrantPages')->willReturn(
            $this->pages([$first, [$this->tokenModel(['catalog.product.get'])]])
        );

        self::assertSame(251, $this->grantDrift->countDriftingActiveTokens());
    }

    /**
     * @param list<list<Token>> $pages
     * @return \Generator<int, list<Token>, mixed, void>
     */
    private function pages(array $pages): \Generator
    {
        yield from $pages;
    }

    /**
     * Review F1a: consent only offers tools inside the client's cap, so only those can be missing.
     *
     * @return void
     */
    public function testCappedClientOnlyCountsToolsInsideItsCap(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')
            ->willReturn(['catalog.product.get', 'cms.page.get', 'sales.order.get']);
        $this->givenClient(3, ['sales.order.get', 'cms.page.get'], once: true);

        self::assertSame(
            ['granted' => 1, 'missing' => ['cms.page.get']],
            $this->grantDrift->forToken($this->token(['sales.order.get'], clientId: 3))
        );
        self::assertSame(
            ['granted' => 1, 'missing' => ['sales.order.get']],
            $this->grantDrift->forToken($this->token(['cms.page.get'], clientId: 3))
        );
    }

    /**
     * @return void
     */
    public function testWildcardClientDoesNotNarrowTheOfferedSet(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);
        $this->givenClient(3, ['*']);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get']],
            $this->grantDrift->forToken($this->token(['sales.order.get'], clientId: 3))
        );
    }

    /**
     * @return void
     */
    public function testMissingClientHasNoDrift(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);
        $this->clientRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('id', 3));

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'], clientId: 3)));
    }

    /**
     * @return void
     */
    public function testDisabledClientHasNoDrift(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);
        $this->givenClient(3, ['*'], disabled: true);

        self::assertNull($this->grantDrift->forToken($this->token(['sales.order.get'], clientId: 3)));
    }

    /**
     * Review F1b: a read-only connection never misses write tools.
     *
     * @return void
     */
    public function testReadOnlyTokenNeverMissesWriteTools(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn([
            'catalog.product.get',
            'catalog.product.update',
            'cms.page.save',
            'sales.order.get',
        ]);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get']],
            $this->grantDrift->forToken($this->token(['sales.order.get'], allowWrites: false))
        );
        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get', 'catalog.product.update', 'cms.page.save']],
            $this->grantDrift->forToken($this->token(['sales.order.get']))
        );
    }

    /**
     * Review F1c: a consent the admin narrowed on purpose, with nothing new since, is not drift.
     *
     * @return void
     */
    public function testNarrowedGrantWithNothingNewSinceTheGrantHasNoDrift(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')
            ->willReturn(['catalog.product.get', 'cms.page.get', 'sales.order.get']);

        self::assertSame(
            ['granted' => 1, 'missing' => []],
            $this->grantDrift->forToken($this->token(
                ['sales.order.get'],
                offered: ['catalog.product.get', 'cms.page.get', 'sales.order.get']
            ))
        );
    }

    /**
     * Only a tool that appeared after the grant counts; what the admin left unticked does not.
     *
     * @return void
     */
    public function testNarrowedGrantCountsOnlyToolsOfferedAfterTheGrant(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')
            ->willReturn(['catalog.product.get', 'cms.page.get', 'sales.order.get', 'system.store.list']);

        self::assertSame(
            ['granted' => 1, 'missing' => ['system.store.list']],
            $this->grantDrift->forToken($this->token(
                ['sales.order.get'],
                offered: ['catalog.product.get', 'cms.page.get', 'sales.order.get']
            ))
        );
    }

    /**
     * The existing narrowing still applies on top of the record: the client cap and read-only tokens.
     *
     * @return void
     */
    public function testToolsOfferedAfterTheGrantStillRespectTheCapAndReadOnly(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')
            ->willReturn(['catalog.product.get', 'catalog.product.update', 'cms.page.get', 'sales.order.get']);
        $this->givenClient(3, ['sales.order.get', 'catalog.product.get', 'catalog.product.update']);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get']],
            $this->grantDrift->forToken($this->token(
                ['sales.order.get'],
                clientId: 3,
                allowWrites: false,
                offered: ['sales.order.get']
            ))
        );
    }

    /**
     * An empty record is a real record: everything offered today is new.
     *
     * @return void
     */
    public function testEmptyRecordCountsEveryOfferedToolOutsideTheGrant(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')->willReturn(['catalog.product.get', 'sales.order.get']);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get']],
            $this->grantDrift->forToken($this->token(['sales.order.get'], offered: []))
        );
    }

    /**
     * Rows written before the record existed keep the old rule.
     *
     * @return void
     */
    public function testLegacyTokenWithoutRecordKeepsTheOldRule(): void
    {
        $this->givenAdmin(7, true);
        $this->consentOfferedTools->method('forAdmin')
            ->willReturn(['catalog.product.get', 'cms.page.get', 'sales.order.get']);

        self::assertSame(
            ['granted' => 1, 'missing' => ['catalog.product.get', 'cms.page.get']],
            $this->grantDrift->forToken($this->token(['sales.order.get'], offered: null))
        );
    }

    /**
     * @return void
     */
    public function testAcceptAllWildcardWithRecordHasNoDrift(): void
    {
        $this->consentOfferedTools->expects(self::never())->method('forAdmin');

        self::assertNull($this->grantDrift->forToken($this->token(null, offered: ['catalog.product.get'])));
    }

    /**
     * @param int $id
     * @param array<int, string> $allowedTools
     * @param bool $disabled
     * @param bool $once
     * @return void
     */
    private function givenClient(int $id, array $allowedTools, bool $disabled = false, bool $once = false): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getAllowedTools')->willReturn($allowedTools);
        $client->method('isDisabled')->willReturn($disabled);
        $this->clientRepository->expects($once ? self::once() : self::any())->method('getById')
            ->with($id)->willReturn($client);
    }

    /**
     * @param WriteMode $mode
     * @return ToolInterface
     */
    private function tool(WriteMode $mode): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getWriteMode')->willReturn($mode);
        return $tool;
    }

    /**
     * @param int $id
     * @param bool $active
     * @param bool $once
     * @return void
     */
    private function givenAdmin(int $id, bool $active, bool $once = false): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn($id);
        $admin->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null): mixed => $key === 'is_active' ? ($active ? '1' : '0') : null
        );
        $this->adminUserLookup->expects($once ? self::once() : self::any())->method('getById')
            ->with($id)->willReturn($admin);
    }

    /**
     * @param array<int, string>|null $scopes
     * @param bool $revoked
     * @param bool $expired
     * @param int|null $clientId
     * @param bool $allowWrites
     * @param array<int, string>|null $offered
     * @return TokenInterface
     */
    private function token(
        ?array $scopes,
        bool $revoked = false,
        bool $expired = false,
        ?int $clientId = null,
        bool $allowWrites = true,
        ?array $offered = null
    ): TokenInterface {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getOfferedTools')->willReturn($offered);
        $token->method('getOAuthClientId')->willReturn($clientId);
        $token->method('getAllowWrites')->willReturn($allowWrites);
        $token->method('getScopes')->willReturn($scopes);
        $token->method('isRevoked')->willReturn($revoked);
        $token->method('isExpired')->willReturn($expired);
        $token->method('getAdminUserId')->willReturn(7);
        return $token;
    }

    /**
     * @param array<int, string> $scopes
     * @return Token&MockObject
     */
    private function tokenModel(array $scopes): Token&MockObject
    {
        $token = $this->createMock(Token::class);
        $token->method('getAllowWrites')->willReturn(true);
        $token->method('getScopes')->willReturn($scopes);
        $token->method('isRevoked')->willReturn(false);
        $token->method('isExpired')->willReturn(false);
        $token->method('getAdminUserId')->willReturn(7);
        return $token;
    }
}
