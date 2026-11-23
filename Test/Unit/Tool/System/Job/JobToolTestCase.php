<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Job\JobRepository;
use Magebit\Mcp\Model\Job\JobToolAccess;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Shared wiring for the job tool tests: caller admin 5 on a wildcard token, registered tools
 * `catalog.product.bulk_update` and `catalog.category.bulk_update` whose resources the ACL mock allows unless a
 * test denies them. {@see grant()} switches the token to a literal grant.
 */
abstract class JobToolTestCase extends TestCase
{
    protected const ADMIN_ID = 5;
    protected const TOOL = 'catalog.product.bulk_update';
    protected const OTHER_TOOL = 'catalog.category.bulk_update';

    /** @var JobRepository&MockObject */
    protected $jobs;
    /** @var ToolRegistryInterface&MockObject */
    protected $registry;
    /** @var AclChecker&MockObject */
    protected $acl;
    protected AuthenticatedContextProvider $contextProvider;
    protected JobToolAccess $access;
    /** @var array<string, bool> */
    protected array $denied = [];

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(JobRepository::class);
        $this->registry = $this->createMock(ToolRegistryInterface::class);
        $tool = $this->createMock(ToolWithUnderlying::class);
        $tool->method('getAclResource')->willReturn('Magebit_Mcp::tools');
        $tool->method('getUnderlyingAclResource')->willReturn('Magento_Catalog::products');
        $this->registry->method('get')->willReturnCallback(
            fn (string $n): ToolInterface => in_array($n, [self::TOOL, self::OTHER_TOOL], true)
                ? $tool
                : throw new NoSuchEntityException()
        );
        $this->registry->method('all')->willReturn([self::TOOL => $tool, self::OTHER_TOOL => $tool]);
        $this->acl = $this->createMock(AclChecker::class);
        $this->acl->method('isAllowed')->willReturnCallback(
            fn (User $u, string $r): bool => !isset($this->denied[$r])
        );
        $this->contextProvider = new AuthenticatedContextProvider();
        $this->grant(null);
        $this->access = new JobToolAccess($this->jobs, $this->registry, $this->acl, $this->contextProvider);
    }

    /**
     * Puts the caller on a token with the given grant: null is the wildcard, a list is a literal grant.
     *
     * @param list<string>|null $scopes
     * @return void
     */
    protected function grant(?array $scopes): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(self::ADMIN_ID);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn($scopes);
        $this->contextProvider->set(new AuthenticatedContext($token, $admin));
    }

    /**
     * @param string $state
     * @param int $adminId
     * @param array<string, mixed> $extra
     * @return JobInterface&MockObject
     */
    protected function job(string $state, int $adminId = self::ADMIN_ID, array $extra = []): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getJobId')->willReturn('j1');
        $job->method('getToolName')->willReturn($extra['tool'] ?? self::TOOL);
        $job->method('getAdminUserId')->willReturn($adminId);
        $job->method('getState')->willReturn($state);
        $job->method('isDryRun')->willReturn(false);
        $job->method('getTotal')->willReturn($extra['total'] ?? 3);
        $job->method('getOk')->willReturn(1);
        $job->method('getFailed')->willReturn(0);
        $job->method('getSkipped')->willReturn(0);
        $job->method('getNextIndex')->willReturn($extra['next'] ?? 1);
        $job->method('getError')->willReturn($extra['error'] ?? null);
        $job->method('getCreatedAt')->willReturn('2026-11-20 10:00:00');
        $job->method('getStartedAt')->willReturn(null);
        $job->method('getFinishedAt')->willReturn(null);
        $job->method('getItems')->willReturn($extra['items'] ?? []);
        return $job;
    }

    /**
     * @param ToolResultInterface $result
     * @return string
     */
    protected function text(ToolResultInterface $result): string
    {
        $text = $result->getContent()[0]['text'] ?? null;
        self::assertIsString($text);
        return $text;
    }

    /**
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    protected function decode(ToolResultInterface $result): array
    {
        $data = json_decode($this->text($result), true);
        self::assertIsArray($data);
        return $data;
    }

    /**
     * @param array<string, mixed> $schema
     * @param string $property
     * @return array<string, mixed>
     */
    protected function property(array $schema, string $property): array
    {
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        $one = $properties[$property];
        self::assertIsArray($one);
        return $one;
    }
}
