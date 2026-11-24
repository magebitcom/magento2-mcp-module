<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use Magebit\Mcp\Api\BulkItemAuditInterface;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\BulkToolLifecycleInterface;
use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\JobSnapshotProviderInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\AuditLog\AuditContext;
use Magebit\Mcp\Model\AuditLog\AuditContextFactory;
use Magebit\Mcp\Model\AuditLog\AuditLogger;
use Magebit\Mcp\Model\Config\FreshDefaultConfigReader;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Job\JobAccessGuard;
use Magebit\Mcp\Model\Job\JobItemKeys;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobRunner;
use Magebit\Mcp\Model\Job\SnapshotProviderPool;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Model\Tool\Bulk\BulkExecutor;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\ItemResult;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\ToolAvailability;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\State;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface JobRunnerTestLifecycleTool extends BulkToolInterface, BulkToolLifecycleInterface
{
}

interface JobRunnerTestAuditTool extends JobRunnerTestLifecycleTool, BulkItemAuditInterface
{
}

class JobRunnerTest extends TestCase
{
    private const JOB_ID = '11111111-2222-3333-4444-555555555555';
    private const TOOL = 'catalog.product.update';
    private const UNEXPECTED = 'The job stopped unexpectedly; see the store log.';
    private const RUN_ID = 'aaaaaaaa-0000-0000-0000-000000000001';

    /**
     * @phpstan-var JobRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobRepository&MockObject $jobs;

    /**
     * @phpstan-var JobRunnerTestLifecycleTool&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private JobRunnerTestLifecycleTool&MockObject $tool;

    /**
     * @phpstan-var ToolRegistryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolRegistryInterface&MockObject $registry;

    /**
     * @phpstan-var ToolAvailability&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolAvailability&MockObject $availability;

    /**
     * @phpstan-var FreshDefaultConfigReader&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private FreshDefaultConfigReader&MockObject $config;

    private bool $storeAllowsWrites = true;

    private string $disabledToolList = '';

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokens;

    /**
     * @phpstan-var Token&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Token&MockObject $token;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $admins;

    /**
     * @phpstan-var User&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private User&MockObject $admin;

    /**
     * @phpstan-var State&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private State&MockObject $appState;

    /**
     * @phpstan-var AuditLogger&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AuditLogger&MockObject $auditLogger;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private AuthenticatedContextProvider $contextProvider;

    /**
     * @phpstan-var AclBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AclBuilder&MockObject $aclBuilder;

    /**
     * @var array<string, JobSnapshotProviderInterface>
     */
    private array $snapshotProviders = [];

    /**
     * @var list<string> ACL resources the admin role does not allow.
     */
    private array $deniedResources = [];

    /**
     * @var list<array{0: string, 1: ?string}> finish() calls as [state, error].
     */
    private array $finished = [];

    /**
     * @var list<AuditContext>
     */
    private array $audits = [];

    private bool $adminActive = true;

    private bool $serverEnabled = true;

    private ?int $tokenClientId = null;

    /**
     * @var array<int, bool> OAuth clients that exist, by id, mapped to their disabled flag.
     */
    private array $clients = [5 => false];

    /**
     * @phpstan-var IdentityGeneratorInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IdentityGeneratorInterface&MockObject $runIds;

    private bool $tokenAllowsWrites = true;

    private bool $tokenRevoked = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->jobs = $this->freshJobs();
        $this->jobs->method('recordItem')->willReturn(true);

        $this->tool = $this->createMock(JobRunnerTestLifecycleTool::class);
        $this->tool->method('getName')->willReturn(self::TOOL);
        $this->tool->method('getItemKeyField')->willReturn('sku');
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('getBulkTool')->willReturnCallback(fn (): BulkToolInterface => $this->tool);
        $adapter->method('getName')->willReturn(self::TOOL);
        $adapter->method('getAclResource')->willReturn('Vendor_Module::mcp_tool_catalog_product_update');
        $adapter->method('getUnderlyingAclResource')->willReturn('Magento_Catalog::products');
        $adapter->method('getWriteMode')->willReturn(WriteMode::WRITE);
        $this->registry = $this->createMock(ToolRegistryInterface::class);
        $this->registry->method('get')->willReturnCallback(
            function (string $name) use ($adapter): BulkToolAdapter {
                if ($name !== self::TOOL) {
                    throw NoSuchEntityException::singleField('name', $name);
                }
                return $adapter;
            }
        );

        $this->availability = $this->createMock(ToolAvailability::class);
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config = $this->createMock(FreshDefaultConfigReader::class);
        $this->config->method('isSetFlag')->willReturnCallback(fn (string $path): bool => match ($path) {
            ModuleConfig::XML_PATH_ENABLED => $this->serverEnabled,
            ModuleConfig::XML_PATH_ALLOW_WRITES => $this->storeAllowsWrites,
            default => false,
        });
        $this->config->method('get')->willReturnCallback(
            fn (string $path): ?string => $path === DisabledTools::XML_PATH_DISABLED_TOOLS
                ? $this->disabledToolList
                : null
        );

        $this->token = $this->createMock(Token::class);
        $this->token->method('getId')->willReturn(3);
        $this->token->method('getOAuthClientId')->willReturnCallback(fn (): ?int => $this->tokenClientId);
        $this->token->method('getAllowWrites')->willReturnCallback(fn (): bool => $this->tokenAllowsWrites);
        $this->token->method('isRevoked')->willReturnCallback(fn (): bool => $this->tokenRevoked);
        $this->tokens = $this->createMock(TokenRepository::class);
        $this->tokens->method('getById')->willReturnCallback(function (int $id): Token {
            if ($id !== 3) {
                throw NoSuchEntityException::singleField('id', $id);
            }
            return $this->token;
        });

        $this->admin = $this->createMock(User::class);
        $this->admin->method('getId')->willReturn(7);
        $this->admin->method('getIsActive')->willReturnCallback(fn (): string => $this->adminActive ? '1' : '0');
        $this->admins = $this->createMock(AdminUserLookup::class);
        $this->admins->method('getById')->willReturnCallback(function (int $id): User {
            if ($id !== 7) {
                throw NoSuchEntityException::singleField('user_id', $id);
            }
            return $this->admin;
        });

        $this->appState = $this->createMock(State::class);
        $this->appState->method('emulateAreaCode')->willReturnCallback(
            static fn (string $area, callable $callback, array $params = []): mixed => $callback(...$params)
        );
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->method('write')->willReturnCallback(function (AuditContext $context): void {
            $this->audits[] = $context;
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->contextProvider = new AuthenticatedContextProvider();
        $this->aclBuilder = $this->createMock(AclBuilder::class);
        $this->runIds = $this->createMock(IdentityGeneratorInterface::class);
        $this->runIds->method('generateId')->willReturn(self::RUN_ID);
    }

    /**
     * @param BulkExecutor|null $executor
     * @return JobRunner
     */
    private function runner(?BulkExecutor $executor = null): JobRunner
    {
        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturnCallback(
            fn (User $user, string $resource): bool => !in_array($resource, $this->deniedResources, true)
        );
        $clients = $this->createMock(ClientRepository::class);
        $clients->method('getById')->willReturnCallback(function (int $id): Client {
            if (!array_key_exists($id, $this->clients)) {
                throw NoSuchEntityException::singleField('id', $id);
            }
            $client = $this->createMock(Client::class);
            $client->method('isDisabled')->willReturn($this->clients[$id]);
            return $client;
        });
        $guard = new JobAccessGuard(
            $this->registry,
            $this->availability,
            $this->aclBuilder,
            $aclChecker,
            $this->config,
            $this->tokens,
            $this->admins,
            $clients
        );
        $auditFactory = $this->createMock(AuditContextFactory::class);
        $auditFactory->method('create')->willReturnCallback(static fn (): AuditContext => new AuditContext());

        return new JobRunner(
            $this->jobs,
            $guard,
            $this->contextProvider,
            $executor ?? new BulkExecutor($aclChecker, $this->logger),
            new SnapshotProviderPool($this->snapshotProviders),
            $this->appState,
            $this->auditLogger,
            $auditFactory,
            $this->logger,
            $this->runIds,
            new JobItemKeys($this->registry)
        );
    }

    /**
     * A repository mock that claims, keeps the heartbeat (unless told not to) and records finish() calls (unless
     * told not to).
     *
     * @param bool $recordFinish
     * @param bool $keepHeartbeat
     * @param bool $markItems Answer every in-flight marker write with MARK_RUN.
     * @return JobRepository&MockObject
     */
    private function freshJobs(
        bool $recordFinish = true,
        bool $keepHeartbeat = true,
        bool $markItems = true
    ): JobRepository&MockObject {
        $jobs = $this->createMock(JobRepository::class);
        $jobs->method('claim')->willReturn(true);
        if ($markItems) {
            $jobs->method('markItemRunning')->willReturn(JobRepository::MARK_RUN);
        }
        if ($keepHeartbeat) {
            $jobs->method('heartbeat')->willReturn(true);
        }
        if ($recordFinish) {
            $jobs->method('finish')->willReturnCallback(
                function (string $jobId, string $runId, string $state, ?string $error): bool {
                    self::assertSame(self::RUN_ID, $runId);
                    $this->finished[] = [$state, $error];
                    return true;
                }
            );
        }
        return $jobs;
    }

    /**
     * Wires getByJobId: the first read returns the claimed job, later reads (the audit) the final state.
     *
     * @param array<string, mixed> $overrides
     * @return void
     */
    private function givenJob(array $overrides = []): void
    {
        $data = $overrides + [
            'items' => [['sku' => 'A'], ['sku' => 'B']],
            'next_index' => 0,
            'dry_run' => false,
            'admin_user_id' => 7,
            'token_id' => 3,
            'oauth_client_id' => null,
        ];
        $items = $data['items'];
        self::assertIsArray($items);
        $claimed = $this->job($data, JobInterface::STATE_RUNNING);
        $reads = 0;
        $this->jobs->method('getByJobId')->willReturnCallback(
            function (string $jobId) use ($claimed, $data, &$reads): JobInterface {
                $reads++;
                if ($reads === 1) {
                    return $claimed;
                }
                $last = end($this->finished);
                return $this->job($data, $last === false ? JobInterface::STATE_RUNNING : $last[0]);
            }
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param string $state
     * @return JobInterface&MockObject
     */
    private function job(array $data, string $state): JobInterface&MockObject
    {
        $items = is_array($data['items']) ? array_values($data['items']) : [];
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn(self::JOB_ID);
        $job->method('getToolName')->willReturn(self::TOOL);
        $job->method('getAdminUserId')->willReturn($data['admin_user_id']);
        $job->method('getTokenId')->willReturn($data['token_id']);
        $job->method('getOauthClientId')->willReturn($data['oauth_client_id'] ?? null);
        $job->method('isDryRun')->willReturn($data['dry_run']);
        $job->method('getItems')->willReturn($items);
        $job->method('getTotal')->willReturn(count($items));
        $job->method('getNextIndex')->willReturn($data['next_index']);
        $job->method('getState')->willReturn($state);
        $job->method('getOk')->willReturn(2);
        $job->method('getFailed')->willReturn(0);
        $job->method('getSkipped')->willReturn(0);
        return $job;
    }

    /**
     * @return void
     */
    public function testSecondClaimDoesNothing(): void
    {
        $this->jobs = $this->createMock(JobRepository::class);
        $this->jobs->expects(self::once())->method('claim')->with(self::JOB_ID, self::RUN_ID)->willReturn(false);
        $this->jobs->expects(self::never())->method('getByJobId');
        $this->jobs->expects(self::never())->method('recordItem');
        $this->jobs->expects(self::never())->method('finish');
        $executor = $this->createMock(BulkExecutor::class);
        $executor->expects(self::never())->method('run');
        $this->auditLogger->expects(self::never())->method('write');

        $this->runner($executor)->process(self::JOB_ID);
    }

    /**
     * @return void
     */
    public function testResumesFromNextIndex(): void
    {
        $this->givenJob(['items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C'], ['sku' => 'D']], 'next_index' => 2]);
        $processed = [];
        $this->tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );
        $recorded = [];
        $this->jobs = $this->rebuildJobsRecording($recorded);

        $this->runner()->process(self::JOB_ID);

        self::assertSame(['C', 'D'], $processed);
        self::assertSame([[2, 'C', 3], [3, 'D', 4]], $recorded);
        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @return void
     */
    public function testCancelMarksRemainingSkipped(): void
    {
        $this->givenJob(['items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C'], ['sku' => 'D']]]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $checks = 0;
        $this->jobs->method('isCancelRequested')->willReturnCallback(static function () use (&$checks): bool {
            return ++$checks >= 3;
        });
        $this->jobs->expects(self::once())->method('markRemainingCancelled')
            ->with(self::JOB_ID, 2, [2 => 'C', 3 => 'D']);

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_CANCELLED, null]], $this->finished);
    }

    /**
     * @return void
     */
    public function testCancelLostToAnotherActorSkipsMarking(): void
    {
        $this->jobs = $this->freshJobs(false);
        $this->jobs->method('isCancelRequested')->willReturn(true);
        $this->jobs->method('finish')->willReturn(false);
        $this->givenJob();
        $this->jobs->expects(self::never())->method('markRemainingCancelled');
        $this->logger->expects(self::atLeastOnce())->method('warning');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function permissionLossProvider(): array
    {
        return [
            'role ACL' => ['acl'],
            'underlying ACL' => ['underlying'],
            'store writes off' => ['store_writes'],
            'token writes off' => ['token_writes'],
            'admin inactive' => ['admin_inactive'],
            'admin deleted' => ['admin_deleted'],
            'token deleted' => ['token_deleted'],
            'token id null' => ['token_null'],
            'tool disabled' => ['tool_disabled'],
            'tool unavailable' => ['tool_unavailable'],
            'tool gone' => ['tool_gone'],
            'server off' => ['server_off'],
            'OAuth client disabled' => ['client_disabled'],
            'OAuth client deleted' => ['client_deleted'],
            'OAuth client nulled on the job, token still links it' => ['client_nulled_on_job'],
        ];
    }

    /**
     * @dataProvider permissionLossProvider
     * @param string $case
     * @return void
     */
    public function testPermissionLostFailsJob(string $case): void
    {
        $overrides = [];
        $this->adminActive = $case !== 'admin_inactive';
        $this->tokenAllowsWrites = $case !== 'token_writes';
        switch ($case) {
            case 'acl':
                $this->deniedResources = ['Vendor_Module::mcp_tool_catalog_product_update'];
                break;
            case 'underlying':
                $this->deniedResources = ['Magento_Catalog::products'];
                break;
            case 'store_writes':
                $this->storeAllowsWrites = false;
                break;
            case 'admin_deleted':
                $overrides['admin_user_id'] = 99;
                break;
            case 'token_deleted':
                $overrides['token_id'] = 99;
                break;
            case 'token_null':
                $overrides['token_id'] = null;
                break;
            case 'tool_disabled':
                $this->disabledToolList = "system.cache.flush\n" . self::TOOL;
                break;
            case 'tool_unavailable':
                $this->availability = $this->rebuildAvailability(false);
                break;
            case 'tool_gone':
                $this->registry = $this->rebuildEmptyRegistry();
                break;
            case 'server_off':
                $this->serverEnabled = false;
                break;
            case 'client_disabled':
                $this->clients = [5 => true];
                $overrides['oauth_client_id'] = 5;
                $this->tokenClientId = 5;
                break;
            case 'client_deleted':
                $this->clients = [];
                $overrides['oauth_client_id'] = 5;
                $this->tokenClientId = 5;
                break;
            case 'client_nulled_on_job':
                $this->clients = [];
                $this->tokenClientId = 5;
                break;
        }
        $this->givenJob($overrides);
        $this->tool->expects(self::never())->method('beforeBatch');
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->finished);
        self::assertSame(JobInterface::STATE_FAILED, $this->finished[0][0]);
        self::assertStringStartsWith('FORBIDDEN: ', (string) $this->finished[0][1]);
        self::assertStringNotContainsString('catalog.product.update', (string) $this->finished[0][1]);
        self::assertCount(1, $this->audits);
        self::assertSame('error', $this->audits[0]->responseStatus);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function snapshotProvider(): array
    {
        return [
            'first run' => [0, true],
            'resumed run' => [1, false],
        ];
    }

    /**
     * @dataProvider snapshotProvider
     * @param int $nextIndex
     * @param bool $expected
     * @return void
     */
    public function testSnapshotOnFirstRunOnly(int $nextIndex, bool $expected): void
    {
        $provider = $this->createMock(JobSnapshotProviderInterface::class);
        $provider->method('getFormat')->willReturn('json');
        $provider->expects($expected ? self::once() : self::never())->method('export')
            ->with($this->tool, [['sku' => 'A'], ['sku' => 'B']])
            ->willReturn('{"A":1}');
        $this->snapshotProviders = [self::TOOL => $provider];
        $this->givenJob(['next_index' => $nextIndex]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->jobs->expects($expected ? self::once() : self::never())->method('saveSnapshot')
            ->with(self::JOB_ID, 'json', '{"A":1}');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * Review lap 1 #3: a worker died during item 0, so the resumed job is at next_index 0 again. Its snapshot
     * holds the before-state; exporting again would read the after-state of item 0 and is not run at all.
     *
     * @return void
     */
    public function testResumeAtIndexZeroKeepsTheFirstSnapshot(): void
    {
        $provider = $this->createMock(JobSnapshotProviderInterface::class);
        $provider->expects(self::never())->method('export');
        $this->snapshotProviders = [self::TOOL => $provider];
        $this->jobs->expects(self::once())->method('hasSnapshot')->with(self::JOB_ID)->willReturn(true);
        $this->jobs->expects(self::never())->method('saveSnapshot');
        $this->givenJob(['next_index' => 0]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @return void
     */
    public function testNoSnapshotForDryRun(): void
    {
        $provider = $this->createMock(JobSnapshotProviderInterface::class);
        $provider->expects(self::never())->method('export');
        $this->snapshotProviders = [self::TOOL => $provider];
        $this->givenJob(['dry_run' => true]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->jobs->expects(self::never())->method('saveSnapshot');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * PR #48 review round 3 lap: a dry run changes nothing, so its items need no in-flight marker; an interrupted
     * dry-run item is simply checked again on resume instead of being reported as possibly applied.
     *
     * @return void
     */
    public function testDryRunJobWritesNoInFlightMarker(): void
    {
        $this->jobs->expects(self::never())->method('markItemRunning');
        $this->givenJob(['dry_run' => true]);
        $this->tool->expects(self::exactly(2))->method('processItem')->willReturn(ItemResult::ok());

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function snapshotFailureProvider(): array
    {
        return [
            'localized' => [
                new LocalizedException(new Phrase('Product export is locked.')),
                'Snapshot failed: Product export is locked.',
            ],
            'unexpected' => [
                new RuntimeException('SQLSTATE[42S02] secret'),
                'Snapshot failed: the snapshot could not be taken; see the store log.',
            ],
        ];
    }

    /**
     * @dataProvider snapshotFailureProvider
     * @param \Throwable $exception
     * @param string $expected
     * @return void
     */
    public function testSnapshotFailureFailsJobBeforeAnyItem(\Throwable $exception, string $expected): void
    {
        $provider = $this->createMock(JobSnapshotProviderInterface::class);
        $provider->method('export')->willThrowException($exception);
        $this->snapshotProviders = [self::TOOL => $provider];
        $this->givenJob();
        $this->tool->expects(self::never())->method('beforeBatch');
        $this->tool->expects(self::never())->method('processItem');
        $this->jobs->expects(self::never())->method('saveSnapshot');
        $this->logger->expects(self::once())->method('error');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, $expected]], $this->finished);
    }

    /**
     * @return void
     */
    public function testThrowableMarksFailedAndKeepsItems(): void
    {
        $this->givenJob();
        $this->tool->method('beforeBatch')->willThrowException(new RuntimeException('SQLSTATE[HY000] secret'));
        $this->tool->expects(self::never())->method('processItem');
        $this->logger->expects(self::once())->method('error');

        $this->runner()->process(self::JOB_ID);

        // Only `completed` clears items_json, so a failed job keeps its input for resume.
        self::assertSame([[JobInterface::STATE_FAILED, self::UNEXPECTED]], $this->finished);
        self::assertCount(1, $this->audits);
        self::assertSame('error', $this->audits[0]->responseStatus);
    }

    /**
     * @return void
     */
    public function testLocalizedThrowableKeepsItsMessage(): void
    {
        $this->givenJob();
        $this->tool->method('beforeBatch')->willThrowException(
            new LocalizedException(new Phrase('The catalog is being reindexed.'))
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, 'The catalog is being reindexed.']], $this->finished);
    }

    /**
     * @return void
     */
    public function testRecordItemThrowableFailsJob(): void
    {
        $this->jobs = $this->freshJobs();
        $this->jobs->expects(self::once())->method('recordItem')->willThrowException(new RuntimeException('Deadlock'));
        $this->givenJob();
        $this->tool->expects(self::once())->method('processItem')->willReturn(ItemResult::ok());
        // The throw is kept, not raised inside the executor, so the tool's afterBatch still runs.
        $this->tool->expects(self::once())->method('afterBatch');
        $this->logger->expects(self::once())->method('error');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, self::UNEXPECTED]], $this->finished);
    }

    /**
     * Review lap 2: a database fault often breaks the heartbeat before afterBatch too. That throw must not skip
     * the tool's afterBatch, and the first error (the record error) is the one logged and stored.
     *
     * @return void
     */
    public function testHeartbeatThrowingAfterRecordErrorKeepsAfterBatchAndTheRecordError(): void
    {
        $recordError = new RuntimeException('Deadlock');
        $recordFailed = false;
        $this->jobs = $this->freshJobs(true, false);
        $this->jobs->expects(self::once())->method('recordItem')->willReturnCallback(
            static function () use ($recordError, &$recordFailed): bool {
                $recordFailed = true;
                throw $recordError;
            }
        );
        $this->jobs->method('heartbeat')->willReturnCallback(static function () use (&$recordFailed): bool {
            if ($recordFailed) {
                throw new RuntimeException('Lost connection to MySQL server');
            }
            return true;
        });
        $this->givenJob();
        $this->tool->expects(self::once())->method('processItem')->willReturn(ItemResult::ok());
        $this->tool->expects(self::once())->method('afterBatch');
        $this->logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => ($context['exception'] ?? null) === $recordError)
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, self::UNEXPECTED]], $this->finished);
    }

    /**
     * @return void
     */
    public function testLostJobStopsWithoutFinishing(): void
    {
        $this->jobs = $this->freshJobs();
        $this->jobs->expects(self::once())->method('recordItem')->willReturn(false);
        $this->jobs->expects(self::never())->method('finish');
        $this->jobs->expects(self::never())->method('markRemainingCancelled');
        $this->givenJob(['items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']]]);
        $this->tool->expects(self::once())->method('processItem')->willReturn(ItemResult::ok());
        $this->logger->expects(self::once())->method('warning');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * Review Focus 2: the stale cron failed the job during a slow item, the agent resumed it and another worker
     * claimed it under a new run id. The old run must stop at its next write and must not finish the job.
     *
     * @return void
     */
    public function testTakenOverRunStops(): void
    {
        $owner = self::RUN_ID;
        $this->jobs = $this->freshJobs();
        $this->jobs->expects(self::exactly(2))->method('recordItem')->willReturnCallback(
            static function (string $jobId, string $runId, array $row) use (&$owner): bool {
                $owned = $runId === $owner;
                // Item A was the slow one: while it ran, run B claimed the resumed job.
                $owner = 'bbbbbbbb-0000-0000-0000-000000000002';
                return $owned;
            }
        );
        $this->jobs->expects(self::never())->method('finish');
        $this->jobs->expects(self::never())->method('markRemainingCancelled');
        $this->givenJob(['items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']]]);
        $processed = [];
        $this->tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );
        $this->logger->expects(self::once())->method('warning');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(['A', 'B'], $processed);
        self::assertCount(1, $this->audits);
    }

    /**
     * @return void
     */
    public function testHeartbeatLostAfterSnapshotRunsNothing(): void
    {
        $this->jobs = $this->freshJobs(true, false);
        $this->jobs->expects(self::once())->method('heartbeat')->with(self::JOB_ID, self::RUN_ID)->willReturn(false);
        $this->jobs->expects(self::never())->method('finish');
        $this->givenJob();
        $this->tool->expects(self::never())->method('beforeBatch');
        $this->tool->expects(self::never())->method('processItem');
        $this->logger->expects(self::once())->method('warning');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * @return void
     */
    public function testHeartbeatLostAfterBeforeBatchRunsNoItem(): void
    {
        $this->jobs = $this->freshJobs(true, false);
        $beats = 0;
        $this->jobs->expects(self::exactly(2))->method('heartbeat')
            ->willReturnCallback(static function () use (&$beats): bool {
                return ++$beats === 1;
            });
        $this->jobs->expects(self::never())->method('finish');
        $this->givenJob();
        $this->tool->expects(self::once())->method('beforeBatch');
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * Review lap 1 #5: afterBatch may be slow, so the heartbeat is refreshed right before it.
     *
     * @return void
     */
    public function testHeartbeatIsRefreshedRightBeforeAfterBatch(): void
    {
        $calls = [];
        $this->jobs = $this->freshJobs(true, false);
        $this->jobs->method('recordItem')->willReturn(true);
        $this->jobs->method('heartbeat')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'heartbeat';
            return true;
        });
        $this->givenJob();
        $this->tool->method('processItem')->willReturnCallback(static function () use (&$calls): ItemResult {
            $calls[] = 'item';
            return ItemResult::ok();
        });
        $this->tool->expects(self::once())->method('afterBatch')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'afterBatch';
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame(['item', 'item', 'heartbeat', 'afterBatch'], array_slice($calls, -4));
        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @return void
     */
    public function testHeartbeatLostBeforeAfterBatchSkipsItAndLeavesTheJob(): void
    {
        $this->jobs = $this->freshJobs(true, false);
        $this->jobs->method('recordItem')->willReturn(true);
        $beats = 0;
        // After the snapshot, at the first item check, then before afterBatch: the third is lost.
        $this->jobs->expects(self::exactly(3))->method('heartbeat')
            ->willReturnCallback(static function () use (&$beats): bool {
                return ++$beats < 3;
            });
        $this->jobs->expects(self::never())->method('finish');
        $this->givenJob();
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->tool->expects(self::never())->method('afterBatch');
        $this->logger->expects(self::once())->method('warning');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function afterBatchNoticeProvider(): array
    {
        return [
            'completed' => [false, JobInterface::STATE_COMPLETED],
            'cancelled' => [true, JobInterface::STATE_CANCELLED],
        ];
    }

    /**
     * Review lap 1 #5: the after-batch notice a sync call returns is kept on the job too.
     *
     * @dataProvider afterBatchNoticeProvider
     * @param bool $cancel
     * @param string $state
     * @return void
     */
    public function testAfterBatchNoticeIsStoredOnTheJob(bool $cancel, string $state): void
    {
        $this->givenJob();
        $this->jobs->method('isCancelRequested')->willReturn($cancel);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->tool->method('afterBatch')->willThrowException(new RuntimeException('reindex failed'));

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->finished);
        self::assertSame($state, $this->finished[0][0]);
        self::assertStringContainsString('follow-up step after the batch failed', (string) $this->finished[0][1]);
    }

    /**
     * @return void
     */
    public function testUnreadableInputFailsJob(): void
    {
        $this->jobs = $this->freshJobs();
        $job = $this->createMock(JobInterface::class);
        $job->method('getToolName')->willReturn(self::TOOL);
        $job->method('getAdminUserId')->willReturn(7);
        $job->method('getTokenId')->willReturn(3);
        $job->method('getItems')->willReturn([['sku' => 'A'], 'not an item']);
        $job->method('getTotal')->willReturn(2);
        $job->method('getState')->willReturn(JobInterface::STATE_RUNNING);
        $this->jobs->method('getByJobId')->willReturn($job);
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->finished);
        self::assertSame(JobInterface::STATE_FAILED, $this->finished[0][0]);
        self::assertSame('The stored input of this job could not be read.', $this->finished[0][1]);
    }

    /**
     * @return void
     */
    public function testRunsInAdminhtmlWithTheStoredActors(): void
    {
        $this->appState = $this->createMock(State::class);
        $this->appState->expects(self::once())->method('emulateAreaCode')
            ->with('adminhtml', self::isType('callable'))
            ->willReturnCallback(static fn (string $area, callable $callback): mixed => $callback());
        $this->givenJob();
        $seen = null;
        $this->tool->method('processItem')->willReturnCallback(function () use (&$seen): ItemResult {
            $seen = $this->contextProvider->get();
            return ItemResult::ok();
        });

        $this->runner()->process(self::JOB_ID);

        self::assertNotNull($seen);
        self::assertSame($this->token, $seen->token);
        self::assertSame($this->admin, $seen->adminUser);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function killSwitchMessageProvider(): array
    {
        return [
            'server off' => [
                'server_off',
                'FORBIDDEN: The MCP server is turned off in the store configuration.',
            ],
            'OAuth client disabled' => [
                'client_disabled',
                'FORBIDDEN: The OAuth client this job was started from is disabled or deleted.',
            ],
            'OAuth client deleted' => [
                'client_deleted',
                'FORBIDDEN: The OAuth client this job was started from is disabled or deleted.',
            ],
        ];
    }

    /**
     * Review M1: the server off switch and the OAuth client's state are re-checked when the job is claimed.
     *
     * @dataProvider killSwitchMessageProvider
     * @param string $case
     * @param string $expected
     * @return void
     */
    public function testKillSwitchFailsJobWithItsOwnMessage(string $case, string $expected): void
    {
        $overrides = [];
        if ($case === 'server_off') {
            $this->serverEnabled = false;
        } else {
            $this->clients = $case === 'client_disabled' ? [5 => true] : [];
            $overrides['oauth_client_id'] = 5;
        }
        $this->givenJob($overrides);

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, $expected]], $this->finished);
    }

    /**
     * @return void
     */
    public function testJobOfAnActiveOAuthClientRuns(): void
    {
        $this->tokenClientId = 5;
        // A creating token that is not revoked is used as it is; no stand-in is looked up.
        $this->tokens->expects(self::never())->method('findNewestActiveForClient');
        $this->givenJob(['oauth_client_id' => 5]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function deletedCreatingTokenProvider(): array
    {
        return [
            'token id nulled by the foreign key' => [null],
            'token row missing' => [99],
        ];
    }

    /**
     * Review lap 1 #1: deleting the (rotated, revoked) access token that created an OAuth job must not orphan
     * the job; it runs as the admin on the client's current live token.
     *
     * @dataProvider deletedCreatingTokenProvider
     * @param int|null $tokenId
     * @return void
     */
    public function testOAuthJobWhoseCreatingTokenWasDeletedRunsOnTheClientsLiveToken(?int $tokenId): void
    {
        $live = $this->createMock(Token::class);
        $live->method('getId')->willReturn(12);
        $live->method('getOAuthClientId')->willReturn(5);
        $live->method('getAllowWrites')->willReturn(true);
        $this->tokens->expects(self::once())->method('findNewestActiveForClient')->with(7, 5)->willReturn($live);
        $this->givenJob(['token_id' => $tokenId, 'oauth_client_id' => 5]);
        $seen = null;
        $this->tool->method('processItem')->willReturnCallback(function () use (&$seen): ItemResult {
            $seen = $this->contextProvider->get();
            return ItemResult::ok();
        });

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
        self::assertNotNull($seen);
        self::assertSame($live, $seen->token);
    }

    /**
     * The write gate reads the live token's own flag.
     *
     * @return void
     */
    public function testOAuthJobOnALiveTokenWithoutWritesIsRefused(): void
    {
        $live = $this->createMock(Token::class);
        $live->method('getOAuthClientId')->willReturn(5);
        $live->method('getAllowWrites')->willReturn(false);
        $this->tokens->method('findNewestActiveForClient')->willReturn($live);
        $this->givenJob(['token_id' => null, 'oauth_client_id' => 5]);
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->finished);
        self::assertSame(JobInterface::STATE_FAILED, $this->finished[0][0]);
        self::assertStringStartsWith(
            'FORBIDDEN: Write tools are not allowed for this token',
            (string) $this->finished[0][1]
        );
    }

    /**
     * @return void
     */
    public function testOAuthJobWithoutALiveClientTokenIsRefused(): void
    {
        $this->tokens->expects(self::once())->method('findNewestActiveForClient')->with(7, 5)->willReturn(null);
        $this->givenJob(['token_id' => null, 'oauth_client_id' => 5]);
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: The connection that started this job no longer exists.']],
            $this->finished
        );
    }

    /**
     * @return array<string, array{0: list<string>|null, 1: bool}>
     */
    public static function standInGrantProvider(): array
    {
        return [
            'wildcard grant' => [null, true],
            'literal grant with the tool' => [['system.cache.flush', self::TOOL], true],
            'narrower grant after re-consent' => [['system.cache.flush'], false],
        ];
    }

    /**
     * Follow-up D: the stand-in token's grant must still cover the job's tool.
     *
     * @dataProvider standInGrantProvider
     * @param list<string>|null $scopes
     * @param bool $runs
     * @return void
     */
    public function testStandInTokenGrantIsChecked(?array $scopes, bool $runs): void
    {
        $live = $this->createMock(Token::class);
        $live->method('getOAuthClientId')->willReturn(5);
        $live->method('getAllowWrites')->willReturn(true);
        $live->method('getScopes')->willReturn($scopes);
        $this->tokens->method('findNewestActiveForClient')->willReturn($live);
        $this->givenJob(['token_id' => null, 'oauth_client_id' => 5]);
        $this->tool->method('processItem')->willReturn(ItemResult::ok());

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            $runs
                ? [[JobInterface::STATE_COMPLETED, null]]
                : [[JobInterface::STATE_FAILED, 'FORBIDDEN: This connection is no longer granted catalog_product_update.']],
            $this->finished
        );
    }

    /**
     * The same check on the job's own token, whose grant never changes after issue.
     *
     * @return void
     */
    public function testOwnTokenGrantWithoutTheToolIsRefused(): void
    {
        $this->token->method('getScopes')->willReturn(['system.cache.flush']);
        $this->givenJob();
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: This connection is no longer granted catalog_product_update.']],
            $this->finished
        );
    }

    /**
     * A disabled client keeps its own refusal; no live token is looked up for it.
     *
     * @return void
     */
    public function testOAuthJobOfADisabledClientWithoutItsTokenIsRefusedAsDisabled(): void
    {
        $this->clients = [5 => true];
        $this->tokens->expects(self::never())->method('findNewestActiveForClient');
        $this->givenJob(['token_id' => null, 'oauth_client_id' => 5]);

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[
                JobInterface::STATE_FAILED,
                'FORBIDDEN: The OAuth client this job was started from is disabled or deleted.',
            ]],
            $this->finished
        );
    }

    /**
     * A personal-token job has no connection to fall back to.
     *
     * @return void
     */
    public function testPersonalJobWhoseTokenWasDeletedIsStillRefused(): void
    {
        $this->tokens->expects(self::never())->method('findNewestActiveForClient');
        $this->givenJob(['token_id' => null]);

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: The connection that started this job no longer exists.']],
            $this->finished
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function revokedPersonalTokenProvider(): array
    {
        return [
            'revoke whose job cancel failed' => ['the cancel query failed, so the job is still queued'],
            'job queued after the revoke cancelled the others' => ['an authenticated request inserted it late'],
        ];
    }

    /**
     * PR #48 review round 3 (R2): a personal-token job whose token was revoked is refused when a worker claims it,
     * whatever left it queued.
     *
     * @dataProvider revokedPersonalTokenProvider
     * @param string $why
     * @return void
     */
    public function testPersonalJobWhoseTokenWasRevokedIsRefusedAtClaim(string $why): void
    {
        $this->tokenRevoked = true;
        $this->givenJob();
        $this->tool->expects(self::never())->method('processItem');
        $this->jobs->expects(self::never())->method('markItemRunning');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: The connection that started this job was revoked.']],
            $this->finished,
            $why
        );
    }

    /**
     * OAuth refresh rotation revokes the access token that created the job; the connection lives on through its
     * successor, so the job still runs.
     *
     * @return void
     */
    public function testOAuthJobWhoseCreatingTokenWasRotatedStillRuns(): void
    {
        $this->tokenRevoked = true;
        $this->tokenClientId = 5;
        $live = $this->createMock(Token::class);
        $live->method('getId')->willReturn(12);
        $live->method('getOAuthClientId')->willReturn(5);
        $live->method('getAllowWrites')->willReturn(true);
        $this->tokens->expects(self::once())->method('findNewestUnrevokedForClient')->with(7, 5)->willReturn($live);
        $this->tokens->expects(self::never())->method('findNewestActiveForClient');
        $this->givenJob(['oauth_client_id' => 5]);
        $seen = [];
        $this->tool->expects(self::exactly(2))->method('processItem')->willReturnCallback(
            function () use (&$seen): ItemResult {
                $seen[] = $this->contextProvider->get()->token;
                return ItemResult::ok();
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
        // PR #48 review round 5 (S3): the live successor stands in for the revoked creating token.
        self::assertSame([$live, $live], $seen);
    }

    /**
     * PR #48 round 5 review fix: access tokens live an hour and an idle client refreshes only on its next call, so
     * a still-authorised connection often has no unexpired token when the job is claimed. Its newest unrevoked
     * token stands in even when expired, as an expired (unrevoked) creating token is used as it is.
     *
     * @return void
     */
    public function testOAuthJobWhoseRotatedSuccessorHasExpiredRunsOnTheSuccessor(): void
    {
        $this->tokenRevoked = true;
        $this->tokenClientId = 5;
        $successor = $this->createMock(Token::class);
        $successor->method('getId')->willReturn(12);
        $successor->method('getOAuthClientId')->willReturn(5);
        $successor->method('getAllowWrites')->willReturn(true);
        $successor->method('isExpired')->willReturn(true);
        $this->tokens->expects(self::once())->method('findNewestUnrevokedForClient')->with(7, 5)
            ->willReturn($successor);
        $this->givenJob(['oauth_client_id' => 5]);
        $seen = [];
        $this->tool->expects(self::exactly(2))->method('processItem')->willReturnCallback(
            function () use (&$seen): ItemResult {
                $seen[] = $this->contextProvider->get()->token;
                return ItemResult::ok();
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
        self::assertSame([$successor, $successor], $seen);
    }

    /**
     * The stand-in's own write flag is the one checked.
     *
     * @return void
     */
    public function testRevokedCreatorStandInWithoutWritesIsRefused(): void
    {
        $this->tokenRevoked = true;
        $this->tokenClientId = 5;
        $successor = $this->createMock(Token::class);
        $successor->method('getOAuthClientId')->willReturn(5);
        $successor->method('getAllowWrites')->willReturn(false);
        $this->tokens->method('findNewestUnrevokedForClient')->willReturn($successor);
        $this->givenJob(['oauth_client_id' => 5]);
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->finished);
        self::assertStringStartsWith(
            'FORBIDDEN: Write tools are not allowed for this token',
            (string) $this->finished[0][1]
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function revokedOAuthConnectionProvider(): array
    {
        return [
            'revoke whose job cancel failed' => ['the cancel query failed, so the job is still queued'],
            'job queued after the revoke cancelled the others' => ['an authenticated request inserted it late'],
        ];
    }

    /**
     * PR #48 review round 5 (S3): a revoked creating OAuth token is only accepted while the connection still has an
     * unrevoked access token (expired or not). When the admin revoked the connection itself, no unrevoked token is
     * left and the job is refused at claim, whatever left it queued. A revoke leaves refresh tokens alive, so a live
     * refresh token is deliberately no evidence that the connection is still authorised.
     *
     * @dataProvider revokedOAuthConnectionProvider
     * @param string $why
     * @return void
     */
    public function testOAuthJobWhoseConnectionWasRevokedIsRefusedAtClaim(string $why): void
    {
        $this->tokenRevoked = true;
        $this->tokenClientId = 5;
        $this->tokens->expects(self::once())->method('findNewestUnrevokedForClient')->with(7, 5)->willReturn(null);
        $this->givenJob(['oauth_client_id' => 5]);
        $this->tool->expects(self::never())->method('processItem');
        $this->jobs->expects(self::never())->method('markItemRunning');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: The connection that started this job was revoked.']],
            $this->finished,
            $why
        );
    }

    /**
     * The stand-in for a revoked creating token is checked like any stand-in: its own grant must name the tool.
     *
     * @return void
     */
    public function testRevokedCreatorStandInGrantIsChecked(): void
    {
        $this->tokenRevoked = true;
        $this->tokenClientId = 5;
        $live = $this->createMock(Token::class);
        $live->method('getOAuthClientId')->willReturn(5);
        $live->method('getAllowWrites')->willReturn(true);
        $live->method('getScopes')->willReturn(['system.cache.flush']);
        $this->tokens->method('findNewestUnrevokedForClient')->willReturn($live);
        $this->givenJob(['oauth_client_id' => 5]);
        $this->tool->expects(self::never())->method('processItem');

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: This connection is no longer granted catalog_product_update.']],
            $this->finished
        );
    }

    /**
     * A personal token whose row is gone (the foreign key did not null the job's link yet) is refused as before.
     *
     * @return void
     */
    public function testPersonalJobWhoseTokenRowIsMissingIsRefused(): void
    {
        $this->tokens->expects(self::never())->method('findNewestActiveForClient');
        $this->givenJob(['token_id' => 99]);

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            [[JobInterface::STATE_FAILED, 'FORBIDDEN: The connection that started this job no longer exists.']],
            $this->finished
        );
    }

    /**
     * @return void
     */
    public function testRoleChangesSinceTheConsumerStartedAreSeen(): void
    {
        $this->givenJob();
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->aclBuilder->expects(self::once())->method('resetRuntimeAcl');

        $this->runner()->process(self::JOB_ID);
    }

    /**
     * @return void
     */
    public function testAuditRowWritten(): void
    {
        $this->givenJob();
        $this->tool->method('processItem')->willReturn(ItemResult::ok());

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
        $audit = $this->audits[0];
        self::assertSame('job', $audit->method);
        self::assertSame(self::TOOL, $audit->toolName);
        self::assertSame(3, $audit->tokenId);
        self::assertSame(7, $audit->adminUserId);
        self::assertSame('ok', $audit->responseStatus);
        self::assertIsInt($audit->durationMs);
        self::assertSame([
            'job_id' => self::JOB_ID,
            'state' => JobInterface::STATE_COMPLETED,
            'total' => 2,
            'ok' => 2,
            'failed' => 0,
            'skipped' => 0,
        ], $audit->resultSummary);
    }

    /**
     * The job's audit row carries the per-item detail of the items this run processed.
     *
     * @return void
     */
    public function testJobAuditRowGetsTheItemDetailOfThisRun(): void
    {
        $this->givenJob(['items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']], 'next_index' => 1]);
        $tool = $this->createMock(JobRunnerTestAuditTool::class);
        $tool->method('getName')->willReturn(self::TOOL);
        $tool->method('getItemKeyField')->willReturn('sku');
        $tool->method('processItem')->willReturn(ItemResult::ok());
        $tool->method('getItemAuditDetail')->willReturnCallback(
            static fn (array $item, ItemResult $result, bool $dryRun): array => ['sku' => $item['sku']]
        );
        $this->tool = $tool;

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
        self::assertSame([
            'job_id' => self::JOB_ID,
            'state' => JobInterface::STATE_COMPLETED,
            'total' => 3,
            'ok' => 2,
            'failed' => 0,
            'skipped' => 0,
            'items' => [
                ['index' => 1, 'key' => 'B', 'status' => 'ok', 'detail' => ['sku' => 'B']],
                ['index' => 2, 'key' => 'C', 'status' => 'ok', 'detail' => ['sku' => 'C']],
            ],
        ], $this->audits[0]->resultSummary);
    }

    /**
     * @return void
     */
    public function testAuditFailureNeverEscapes(): void
    {
        $this->givenJob();
        $this->tool->method('processItem')->willReturn(ItemResult::ok());
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->method('write')->willThrowException(new RuntimeException('audit table gone'));
        $this->logger->expects(self::once())->method('error');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * @param list<array{0: int, 1: ?string, 2: int}> $recorded
     * @return JobRepository&MockObject
     */
    private function rebuildJobsRecording(array &$recorded): JobRepository&MockObject
    {
        $jobs = $this->freshJobs();
        $jobs->method('recordItem')->willReturnCallback(
            static function (string $jobId, string $runId, array $row, int $nextIndex) use (&$recorded): bool {
                $recorded[] = [$row['index'], $row['key'], $nextIndex];
                return true;
            }
        );
        $original = $this->jobs;
        $jobs->method('getByJobId')->willReturnCallback(
            static fn (string $jobId): JobInterface => $original->getByJobId($jobId)
        );
        return $jobs;
    }

    /**
     * @param bool $available
     * @return ToolAvailability&MockObject
     */
    private function rebuildAvailability(bool $available): ToolAvailability&MockObject
    {
        $availability = $this->createMock(ToolAvailability::class);
        $availability->method('isAvailable')->willReturn($available);
        return $availability;
    }

    /**
     * @return ToolRegistryInterface&MockObject
     */
    private function rebuildEmptyRegistry(): ToolRegistryInterface&MockObject
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->willThrowException(NoSuchEntityException::singleField('name', self::TOOL));
        return $registry;
    }
    /**
     * PR #48 review round 3 (R1): every item gets its in-flight marker before it runs; its result replaces it.
     *
     * @return void
     */
    public function testEveryItemIsMarkedBeforeItRunsAndRecordedAfter(): void
    {
        $order = [];
        $this->jobs = $this->freshJobs(true, true, false);
        $this->jobs->method('markItemRunning')->willReturnCallback(
            static function (string $jobId, string $runId, int $index, ?string $key) use (&$order): string {
                self::assertSame(self::RUN_ID, $runId);
                $order[] = 'mark ' . $index . ' ' . $key;
                return JobRepository::MARK_RUN;
            }
        );
        $this->jobs->method('recordItem')->willReturnCallback(
            static function (string $jobId, string $runId, array $row) use (&$order): bool {
                $order[] = 'record ' . $row['index'] . ' ' . $row['status'];
                return true;
            }
        );
        $this->givenJob();
        $this->tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$order): ItemResult {
                $order[] = 'process ' . $item['sku'];
                return ItemResult::ok();
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame(
            ['mark 0 A', 'process A', 'record 0 ok', 'mark 1 B', 'process B', 'record 1 ok'],
            $order
        );
        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * The review's case: the item's write succeeded, then saving its result failed. The job fails (resumable);
     * the resume does not run that item again but reports it INTERRUPTED, and goes on with the rest.
     *
     * @return void
     */
    public function testAnItemWhoseResultWasNotSavedIsNeverRunAgainOnResume(): void
    {
        $store = ['rows' => [], 'next' => 0, 'recordFailures' => 1];
        $this->jobs = $this->statefulJobs($store);
        $processed = [];
        $this->tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame(['A'], $processed);
        self::assertSame([[JobInterface::STATE_FAILED, self::UNEXPECTED]], $this->finished);
        self::assertSame(0, $store['next'], 'the cursor stays at the item, so the job is resumable there');
        self::assertSame(['error', 'INTERRUPTED'], [$store['rows'][0]['status'], $store['rows'][0]['error_code']]);

        // system_job_resume, then the next claim.
        $this->runner()->process(self::JOB_ID);

        self::assertSame(['A', 'B'], $processed, 'A ran once');
        self::assertSame(
            [[JobInterface::STATE_FAILED, self::UNEXPECTED], [JobInterface::STATE_COMPLETED, null]],
            $this->finished
        );
        self::assertSame([
            'status' => 'error',
            'error_code' => 'INTERRUPTED',
            'message' => 'The worker stopped while this item was running; it may already have been applied. '
                . 'Check it and send it again in a new call if needed.',
        ], array_intersect_key($store['rows'][0], ['status' => 1, 'error_code' => 1, 'message' => 1]));
        self::assertSame('ok', $store['rows'][1]['status']);
        self::assertSame(2, $store['next']);
        self::assertNotContains('running', array_column($store['rows'], 'status'));
    }

    /**
     * A worker that died mid-item left its marker; the stale cron failed the job and turned the marker into
     * INTERRUPTED. The resume reports that item and runs the next.
     *
     * @return void
     */
    public function testAnInterruptedItemIsReportedAndTheRestRuns(): void
    {
        $this->jobs = $this->freshJobs(true, true, false);
        $this->jobs->method('markItemRunning')->willReturnCallback(
            static fn (string $j, string $r, int $index): string => $index === 1
                ? JobRepository::MARK_INTERRUPTED
                : JobRepository::MARK_RUN
        );
        $recorded = [];
        $this->jobs->method('recordItem')->willReturnCallback(
            static function (string $jobId, string $runId, array $row, int $next) use (&$recorded): bool {
                $recorded[] = [$row['index'], $row['status'], $row['error_code'], $next];
                return true;
            }
        );
        $this->givenJob([
            'items' => [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']],
            'next_index' => 1,
        ]);
        $processed = [];
        $this->tool->method('processItem')->willReturnCallback(
            static function (array $item) use (&$processed): ItemResult {
                $processed[] = $item['sku'];
                return ItemResult::ok();
            }
        );

        $this->runner()->process(self::JOB_ID);

        self::assertSame(['C'], $processed);
        self::assertSame([[1, 'error', 'INTERRUPTED', 2], [2, 'ok', null, 3]], $recorded);
        self::assertSame([[JobInterface::STATE_COMPLETED, null]], $this->finished);
    }

    /**
     * A marker that cannot be written means the item does not run; the job fails through the generic path, so it
     * can be resumed, and the tool's afterBatch still runs.
     *
     * @return void
     */
    public function testAMarkerWriteFailureRunsNothingAndFailsTheJob(): void
    {
        $this->jobs = $this->freshJobs(true, true, false);
        $this->jobs->method('markItemRunning')->willThrowException(new RuntimeException('Deadlock'));
        $this->jobs->expects(self::never())->method('recordItem');
        $this->givenJob();
        $this->tool->expects(self::never())->method('processItem');
        $this->tool->expects(self::once())->method('afterBatch');
        $this->logger->expects(self::once())->method('error');

        $this->runner()->process(self::JOB_ID);

        self::assertSame([[JobInterface::STATE_FAILED, self::UNEXPECTED]], $this->finished);
    }

    /**
     * A run that lost its job at the marker write runs nothing more and leaves the job to its new owner.
     *
     * @return void
     */
    public function testAMarkerWriteOnALostJobRunsNothingAndLeavesTheJob(): void
    {
        $this->jobs = $this->freshJobs(true, true, false);
        $this->jobs->method('markItemRunning')->willReturn(JobRepository::MARK_LOST);
        $this->jobs->expects(self::never())->method('recordItem');
        $this->jobs->expects(self::never())->method('finish');
        $this->givenJob();
        $this->tool->expects(self::never())->method('processItem');
        $this->tool->expects(self::never())->method('afterBatch');
        $this->logger->expects(self::once())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $this->runner()->process(self::JOB_ID);

        self::assertCount(1, $this->audits);
    }

    /**
     * An in-memory job and item table that behaves like JobRepository for the marker, the result write, the
     * cursor and the leftover conversion on finish.
     *
     * @param array{rows: array<int, array<string, mixed>>, next: int, recordFailures: int} $store
     * @return JobRepository&MockObject
     */
    private function statefulJobs(array &$store): JobRepository&MockObject
    {
        $jobs = $this->createMock(JobRepository::class);
        $jobs->method('claim')->willReturn(true);
        $jobs->method('heartbeat')->willReturn(true);
        $jobs->method('markItemRunning')->willReturnCallback(
            static function (string $jobId, string $runId, int $index, ?string $key) use (&$store): string {
                $row = $store['rows'][$index] ?? null;
                if ($row !== null && ($row['status'] === 'running' || $row['error_code'] === 'INTERRUPTED')) {
                    return JobRepository::MARK_INTERRUPTED;
                }
                $store['rows'][$index] = ['status' => 'running', 'error_code' => null, 'message' => null];
                return JobRepository::MARK_RUN;
            }
        );
        $jobs->method('recordItem')->willReturnCallback(
            static function (string $jobId, string $runId, array $row, int $next) use (&$store): bool {
                if ($store['recordFailures'] > 0) {
                    $store['recordFailures']--;
                    throw new RuntimeException('Deadlock found when trying to get lock');
                }
                $store['rows'][$row['index']] = [
                    'status' => $row['status'],
                    'error_code' => $row['error_code'],
                    'message' => $row['message'],
                ];
                $store['next'] = $next;
                return true;
            }
        );
        $jobs->method('finish')->willReturnCallback(
            function (string $jobId, string $runId, string $state, ?string $error) use (&$store): bool {
                $this->finished[] = [$state, $error];
                foreach ($store['rows'] as $index => $row) {
                    if ($row['status'] === 'running') {
                        $store['rows'][$index] = [
                            'status' => 'error',
                            'error_code' => 'INTERRUPTED',
                            'message' => JobRepository::MESSAGE_INTERRUPTED,
                        ];
                    }
                }
                return true;
            }
        );
        $jobs->method('getByJobId')->willReturnCallback(
            fn (string $jobId): JobInterface => $this->job(
                [
                    'items' => [['sku' => 'A'], ['sku' => 'B']],
                    'next_index' => $store['next'],
                    'dry_run' => false,
                    'admin_user_id' => 7,
                    'token_id' => 3,
                    'oauth_client_id' => null,
                ],
                JobInterface::STATE_RUNNING
            )
        );
        return $jobs;
    }
}
