<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

/**
 * Shared gate of the four job tools: a job is only visible to the admin who owns it, only while that admin's
 * role still allows the job's tool, and, for a connection with a literal tool grant, only when the job's tool is
 * in that grant. A wildcard grant reaches all of the admin's own jobs.
 */
class JobToolAccess
{
    /**
     * @param JobRepository $jobs
     * @param ToolRegistryInterface $toolRegistry
     * @param AclChecker $aclChecker
     * @param AuthenticatedContextProvider $contextProvider
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AclChecker $aclChecker,
        private readonly AuthenticatedContextProvider $contextProvider
    ) {
    }

    /**
     * Loads a job the admin owns. Another admin's job answers exactly like an unknown id.
     *
     * @param string $jobId
     * @param User $admin
     * @return JobInterface
     * @throws LocalizedException
     */
    public function loadOwned(string $jobId, User $admin): JobInterface
    {
        try {
            $job = $this->jobs->getByJobId($jobId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Job %1 was not found.', $jobId));
        }
        if ($job->getAdminUserId() !== $this->adminId($admin)) {
            throw new LocalizedException(__('Job %1 was not found.', $jobId));
        }
        return $job;
    }

    /**
     * @param JobInterface $job
     * @param User $admin
     * @return void
     * @throws ForbiddenException
     */
    public function assertAllowed(JobInterface $job, User $admin): void
    {
        if (!$this->isInGrant($job->getToolName())) {
            throw new ForbiddenException(
                'This connection was not granted ' . $this->wireName($job->getToolName())
                . ', so it cannot read or change this job.'
            );
        }
        if (!$this->isToolAllowed($job->getToolName(), $admin)) {
            throw new ForbiddenException(
                'Your admin role no longer allows ' . $this->wireName($job->getToolName())
                . ', so this job cannot be read or changed.'
            );
        }
    }

    /**
     * Canonical names of the registered tools the admin's role allows and the connection's grant includes, for
     * filtering a list before paging.
     *
     * @param User $admin
     * @return list<string>
     */
    public function allowedToolNames(User $admin): array
    {
        $names = [];
        foreach (array_keys($this->toolRegistry->all()) as $name) {
            if ($this->isInGrant((string) $name) && $this->isToolAllowed((string) $name, $admin)) {
                $names[] = (string) $name;
            }
        }
        return $names;
    }

    /**
     * @param string $toolName
     * @return string
     */
    public function wireName(string $toolName): string
    {
        return str_replace('.', '_', $toolName);
    }

    /**
     * Splits a job's stored text for the job tools: on a `completed` or `cancelled` job it is the after-batch
     * notice (the tool's follow-up step failed, the items ran), on any other state it is the error.
     *
     * @param JobInterface $job
     * @return array{error: string|null, notice: string|null}
     */
    public function storedMessages(JobInterface $job): array
    {
        $stored = $job->getError();
        $ended = in_array($job->getState(), [JobInterface::STATE_COMPLETED, JobInterface::STATE_CANCELLED], true);
        return $ended ? ['error' => null, 'notice' => $stored] : ['error' => $stored, 'notice' => null];
    }

    /**
     * True when the calling connection's grant covers the tool: always for a wildcard (null) grant, otherwise only
     * for names in the literal grant. Fails closed when no authenticated context is set.
     *
     * @param string $toolName
     * @return bool
     */
    private function isInGrant(string $toolName): bool
    {
        try {
            $scopes = $this->contextProvider->get()->token->getScopes();
        } catch (LocalizedException) {
            return false;
        }
        return $scopes === null || in_array($toolName, $scopes, true);
    }

    /**
     * @param string $toolName
     * @param User $admin
     * @return bool
     */
    private function isToolAllowed(string $toolName, User $admin): bool
    {
        try {
            $tool = $this->toolRegistry->get($toolName);
        } catch (NoSuchEntityException) {
            return false;
        }
        if (!$this->aclChecker->isAllowed($admin, $tool->getAclResource())) {
            return false;
        }
        if ($tool instanceof UnderlyingAclAwareInterface) {
            $underlying = $tool->getUnderlyingAclResource();
            if ($underlying !== null && !$this->aclChecker->isAllowed($admin, $underlying)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param User $admin
     * @return int
     */
    private function adminId(User $admin): int
    {
        $id = $admin->getId();
        return is_scalar($id) ? (int) $id : 0;
    }
}
