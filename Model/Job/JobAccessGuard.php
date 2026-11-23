<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\WriteAccess;
use Magebit\Mcp\Model\Config\FreshDefaultConfigReader;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\ToolAvailability;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;

/**
 * Re-checks, when a queued job starts, the gates a live `tools/call` passed when the job was created: the MCP
 * server is still turned on, the tool is still registered and available, the admin still exists and is active,
 * the OAuth client the job came from (if any) still exists and is enabled, the role and underlying ACLs still
 * allow the tool, the token's grant (if literal) still names the tool, and a write tool may still write. The three config values (server switch, store write setting,
 * disabled tools) are read fresh through {@see FreshDefaultConfigReader}, and the ACL is rebuilt, because a
 * consumer process outlives config saves and role edits.
 *
 * A job belongs to the connection, not to one access token. OAuth rotation revokes the token that created the
 * job, so when that token was revoked, an OAuth job runs on the connection's newest unrevoked token instead (expired
 * or not: an idle client refreshes only on its next call), and is refused when every token of the connection is
 * revoked (the admin revoked it). When the creating token's row was deleted, the job runs on the connection's newest
 * live token. A personal token has no successor, so a job whose personal token was revoked is refused. An expired,
 * unrevoked creating token is used as it is.
 */
class JobAccessGuard
{
    /**
     * @param ToolRegistryInterface $toolRegistry
     * @param ToolAvailability $toolAvailability
     * @param AclBuilder $aclBuilder
     * @param AclChecker $aclChecker
     * @param FreshDefaultConfigReader $freshConfig
     * @param TokenRepository $tokens
     * @param AdminUserLookup $adminUsers
     * @param ClientRepository $clients
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ToolAvailability $toolAvailability,
        private readonly AclBuilder $aclBuilder,
        private readonly AclChecker $aclChecker,
        private readonly FreshDefaultConfigReader $freshConfig,
        private readonly TokenRepository $tokens,
        private readonly AdminUserLookup $adminUsers,
        private readonly ClientRepository $clients
    ) {
    }

    /**
     * @param JobInterface $job
     * @return array{0: BulkToolAdapter, 1: AuthenticatedContext}
     * @throws ForbiddenException With a plain reason, safe to store on the job.
     */
    public function authorize(JobInterface $job): array
    {
        // The same switch the controller answers -32015 for on a live request. Read fresh, like the three config
        // values here: a consumer process outlives config saves, as it outlives role edits.
        if (!$this->freshConfig->isSetFlag(ModuleConfig::XML_PATH_ENABLED)) {
            throw new ForbiddenException('The MCP server is turned off in the store configuration.');
        }
        $tool = $this->resolveTool($job->getToolName());
        $admin = $this->loadAdmin($job);
        // The job's own client is checked before its token, so a disabled client keeps its own refusal even
        // when the token that created the job is gone.
        $jobClientId = $job->getOauthClientId();
        $this->assertClientActive($jobClientId);
        $token = $this->loadToken($job, $jobClientId);
        if ($jobClientId === null) {
            // A personal token has no successor: once revoked, its jobs stop, even when the revoke's own cancel
            // of them failed or the job was stored after it. An OAuth job's revoked creating token was already
            // replaced in loadToken() by the connection's newest unrevoked token (expired or not), or refused there.
            if ($token->isRevoked()) {
                throw new ForbiddenException('The connection that started this job was revoked.');
            }
            $this->assertClientActive($token->getOAuthClientId());
        }
        $context = new AuthenticatedContext($token, $admin);
        // As the live tools/call scope check: a literal grant must name the tool. This matters for a stand-in
        // token (the connection's newest), whose grant may be narrower than the one that started the job.
        $scopes = $token->getScopes();
        if ($scopes !== null && !in_array($job->getToolName(), $scopes, true)) {
            throw new ForbiddenException(
                'This connection is no longer granted ' . $this->wireName($job->getToolName()) . '.'
            );
        }
        $wireName = $this->wireName($job->getToolName());

        // A consumer process outlives role edits; rebuild the ACL so this check sees the role as it is now.
        $this->aclBuilder->resetRuntimeAcl();
        if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getAclResource())) {
            throw new ForbiddenException('The admin role no longer permits ' . $wireName . '.');
        }
        if (!$this->aclChecker->isAllowed($context->adminUser, $tool->getUnderlyingAclResource())) {
            throw new ForbiddenException(
                'The admin role no longer permits the underlying Magento action of ' . $wireName . '.'
            );
        }
        if ($tool->getWriteMode() === WriteMode::WRITE) {
            $writeAccess = new WriteAccess(
                $this->freshConfig->isSetFlag(ModuleConfig::XML_PATH_ALLOW_WRITES),
                $context->token->getAllowWrites()
            );
            if (!$writeAccess->isAllowed()) {
                throw new ForbiddenException('Write tools are ' . $writeAccess->describeClosedLayer() . '.');
            }
        }

        return [$tool, $context];
    }

    /**
     * @param string $toolName
     * @return BulkToolAdapter
     * @throws ForbiddenException
     */
    private function resolveTool(string $toolName): BulkToolAdapter
    {
        $unavailable = new ForbiddenException(
            'The tool ' . $this->wireName($toolName) . ' is no longer available on this store.'
        );
        $disabled = DisabledTools::parse($this->freshConfig->get(DisabledTools::XML_PATH_DISABLED_TOOLS));
        if (in_array($toolName, $disabled, true)) {
            throw $unavailable;
        }
        try {
            $tool = $this->toolRegistry->get($toolName);
        } catch (NoSuchEntityException) {
            throw $unavailable;
        }
        if (!$tool instanceof BulkToolAdapter || !$this->toolAvailability->isAvailable($tool)) {
            throw $unavailable;
        }
        return $tool;
    }

    /**
     * The stored token, loaded even when revoked (authorize() refuses a revoked personal token). For an OAuth job
     * another token of the same admin and client stands in, because the job belongs to the connection, not to one
     * access token:
     * - the stored one was revoked (rotation, or the admin revoking the connection): the newest unrevoked token,
     *   expired or not, since an idle client refreshes only on its next call. None left means the connection
     *   itself was revoked, so the job is refused even when it was stored after the revoke's own cancel ran. A
     *   live refresh token is not taken as evidence: revoking an access token leaves its refresh token alone;
     * - its row was deleted (the foreign key then nulls `token_id`): the newest live token, as before.
     * A personal-token job has nothing to fall back to.
     *
     * @param JobInterface $job
     * @param int|null $jobClientId
     * @return TokenInterface
     * @throws ForbiddenException
     */
    private function loadToken(JobInterface $job, ?int $jobClientId): TokenInterface
    {
        $stored = null;
        $tokenId = $job->getTokenId();
        if ($tokenId !== null) {
            try {
                $stored = $this->tokens->getById($tokenId);
            } catch (NoSuchEntityException) {
                // Deleted; handled below like a token id the foreign key already cleared.
            }
        }
        if ($jobClientId === null || ($stored !== null && !$stored->isRevoked())) {
            if ($stored === null) {
                throw new ForbiddenException('The connection that started this job no longer exists.');
            }
            return $stored;
        }
        $standIn = $stored === null
            ? $this->tokens->findNewestActiveForClient($job->getAdminUserId(), $jobClientId)
            : $this->tokens->findNewestUnrevokedForClient($job->getAdminUserId(), $jobClientId);
        if ($standIn !== null) {
            return $standIn;
        }
        throw new ForbiddenException(
            $stored === null
                ? 'The connection that started this job no longer exists.'
                : 'The connection that started this job was revoked.'
        );
    }

    /**
     * Refuses a job whose OAuth client was disabled or deleted, as TokenAuthenticator refuses its tokens live.
     *
     * The job's own client id comes first; the stored token's is the fallback. Deleting a client clears both
     * links through their foreign keys, so a job of a deleted client then looks like a personal token's job and
     * nothing is left to check; that is why a client delete cancels the client's jobs first
     * ({@see \Magebit\Mcp\Model\TokenRepository::revokeAllForClient()}), unless it runs with `--keep-tokens`.
     *
     * @param int|null $clientId
     * @return void
     * @throws ForbiddenException
     */
    private function assertClientActive(?int $clientId): void
    {
        if ($clientId === null) {
            return;
        }
        $refused = new ForbiddenException('The OAuth client this job was started from is disabled or deleted.');
        try {
            $client = $this->clients->getById($clientId);
        } catch (NoSuchEntityException) {
            throw $refused;
        }
        if ($client->isDisabled()) {
            throw $refused;
        }
    }

    /**
     * @param JobInterface $job
     * @return User
     * @throws ForbiddenException
     */
    private function loadAdmin(JobInterface $job): User
    {
        try {
            $admin = $this->adminUsers->getById($job->getAdminUserId());
        } catch (NoSuchEntityException) {
            throw new ForbiddenException('The admin user who started this job no longer exists.');
        }
        if ((int) $admin->getIsActive() !== 1) {
            throw new ForbiddenException('The admin user who started this job is no longer active.');
        }
        return $admin;
    }

    /**
     * @param string $toolName
     * @return string
     */
    private function wireName(string $toolName): string
    {
        return str_replace('.', '_', $toolName);
    }
}
