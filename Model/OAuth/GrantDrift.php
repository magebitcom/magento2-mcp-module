<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Finds tokens whose literal (fixed) grant misses tools their admin's role allows.
 *
 * Visibility only: a literal grant is never changed here (frozen grants stay literal). "What the role allows" is
 * what re-authorising would offer today: the consent tree for the admin ({@see ConsentOfferedTools::forAdmin()}),
 * narrowed to the OAuth client's own tool cap, and without write tools on a read-only token. Job tools are computed
 * from the grant, never granted literally, so they never count as missing. Otherwise personal and OAuth tokens are
 * treated the same.
 *
 * A token that carries the "offered at grant" record ({@see TokenInterface::getOfferedTools()}) only counts tools
 * that were not offered then: a consent the admin narrowed on purpose is not drift, a tool that appeared since is.
 * Rows written before the record existed (NULL) keep the rule above.
 */
class GrantDrift
{
    /**
     * Offered tool names per admin id for this request; null when the admin is missing or inactive.
     *
     * @var array<int, list<string>|null>
     */
    private array $offeredByAdmin = [];

    /**
     * Client tool cap per client id for this request: true for the wildcard, null when the client is missing or
     * disabled.
     *
     * @var array<int, array<string, true>|true|null>
     */
    private array $capByClient = [];

    /**
     * @var array<string, true>|null
     */
    private ?array $writeTools = null;

    /**
     * @param ConsentOfferedTools $consentOfferedTools
     * @param JobToolGate $jobToolGate
     * @param AdminUserLookup $adminUserLookup
     * @param TokenRepository $tokenRepository
     * @param ClientRepository $clientRepository
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly ConsentOfferedTools $consentOfferedTools,
        private readonly JobToolGate $jobToolGate,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * The grant size and the canonical, sorted names the role allows but the grant lacks.
     *
     * Null for a wildcard grant, a revoked or expired token, a token whose admin is missing or inactive, and an
     * OAuth token whose client is missing or disabled (it cannot be re-authorised there).
     *
     * A token without `allow_writes` never counts write tools as missing. For an OAuth token that flag stands in for
     * a read-only consent: it is derived from the granted tools, so a connection that asked for `mcp:write` but was
     * granted no write tool is treated as read-only too (this can only under-report drift).
     *
     * With an "offered at grant" record, tools in that record are not counted either: only tools offered since.
     *
     * @param TokenInterface $token
     * @return array{granted: int, missing: list<string>}|null
     */
    public function forToken(TokenInterface $token): ?array
    {
        $scopes = $token->getScopes();
        if ($scopes === null || $token->isRevoked() || $token->isExpired()) {
            return null;
        }
        $offered = $this->offeredFor($token->getAdminUserId());
        if ($offered === null) {
            return null;
        }
        $clientId = $token->getOAuthClientId();
        $cap = $clientId === null ? true : $this->capFor($clientId);
        if ($cap === null) {
            return null;
        }
        $writeTools = $token->getAllowWrites() ? [] : $this->writeTools();
        $offeredAtGrant = $token->getOfferedTools();
        $known = $offeredAtGrant === null ? [] : array_flip($offeredAtGrant);

        $granted = array_flip($scopes);
        $missing = [];
        foreach ($offered as $name) {
            if (isset($granted[$name])
                || isset($known[$name])
                || $this->jobToolGate->isJobTool($name)
                || ($cap !== true && !isset($cap[$name]))
                || isset($writeTools[$name])
            ) {
                continue;
            }
            $missing[] = $name;
        }
        sort($missing, SORT_STRING);

        return ['granted' => count($scopes), 'missing' => $missing];
    }

    /**
     * Number of live tokens with a literal grant that misses at least one tool.
     *
     * Tokens arrive page by page, so only one page is held at a time; the per-admin and per-client memos span pages.
     *
     * @return int
     */
    public function countDriftingActiveTokens(): int
    {
        $count = 0;
        foreach ($this->tokenRepository->getActiveWithLiteralGrantPages() as $page) {
            foreach ($page as $token) {
                $drift = $this->forToken($token);
                if ($drift !== null && $drift['missing'] !== []) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * @param int $adminUserId
     * @return list<string>|null
     */
    private function offeredFor(int $adminUserId): ?array
    {
        if (!array_key_exists($adminUserId, $this->offeredByAdmin)) {
            $this->offeredByAdmin[$adminUserId] = $this->loadOffered($adminUserId);
        }
        return $this->offeredByAdmin[$adminUserId];
    }

    /**
     * @param int $clientId
     * @return array<string, true>|true|null
     */
    private function capFor(int $clientId): array|bool|null
    {
        if (!array_key_exists($clientId, $this->capByClient)) {
            $this->capByClient[$clientId] = $this->loadCap($clientId);
        }
        return $this->capByClient[$clientId];
    }

    /**
     * @param int $clientId
     * @return array<string, true>|true|null
     */
    private function loadCap(int $clientId): array|bool|null
    {
        try {
            $client = $this->clientRepository->getById($clientId);
        } catch (NoSuchEntityException) {
            return null;
        }
        if ($client->isDisabled()) {
            return null;
        }
        $allowed = $client->getAllowedTools();
        if (ToolGrantResolver::isWildcard($allowed)) {
            return true;
        }
        return array_fill_keys($allowed, true);
    }

    /**
     * Names of the registered tools whose write mode is WRITE.
     *
     * @return array<string, true>
     */
    private function writeTools(): array
    {
        if ($this->writeTools === null) {
            $this->writeTools = [];
            foreach ($this->toolRegistry->all() as $name => $tool) {
                if ($tool->getWriteMode() === WriteMode::WRITE) {
                    $this->writeTools[(string) $name] = true;
                }
            }
        }
        return $this->writeTools;
    }

    /**
     * @param int $adminUserId
     * @return list<string>|null
     */
    private function loadOffered(int $adminUserId): ?array
    {
        if ($adminUserId <= 0) {
            return null;
        }
        try {
            $admin = $this->adminUserLookup->getById($adminUserId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $active = $admin->getData('is_active');
        if (!is_scalar($active) || (int) $active !== 1) {
            return null;
        }
        return array_values($this->consentOfferedTools->forAdmin($admin));
    }
}
