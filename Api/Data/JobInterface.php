<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\Data;

/**
 * Background bulk job. Belongs to an admin and a connection (OAuth client or personal token).
 */
interface JobInterface
{
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public const ENTITY_ID = 'entity_id';
    public const JOB_ID = 'job_id';
    public const TOOL_NAME = 'tool_name';
    public const ADMIN_USER_ID = 'admin_user_id';
    public const TOKEN_ID = 'token_id';
    public const OAUTH_CLIENT_ID = 'oauth_client_id';
    public const STATE = 'state';
    public const RUN_ID = 'run_id';
    public const DRY_RUN = 'dry_run';
    public const ITEMS_JSON = 'items_json';
    public const TOTAL = 'total';
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const NEXT_INDEX = 'next_index';
    public const CANCEL_REQUESTED = 'cancel_requested';
    public const ERROR = 'error';
    public const CREATED_AT = 'created_at';
    public const STARTED_AT = 'started_at';
    public const HEARTBEAT_AT = 'heartbeat_at';
    public const FINISHED_AT = 'finished_at';

    /**
     * @return string
     */
    public function getJobId(): string;

    /**
     * @return string
     */
    public function getToolName(): string;

    /**
     * @return int
     */
    public function getAdminUserId(): int;

    /**
     * @return int|null
     */
    public function getTokenId(): ?int;

    /**
     * @return int|null
     */
    public function getOauthClientId(): ?int;

    /**
     * @return string
     */
    public function getState(): string;

    /**
     * Token of the worker run that owns the job while it is `running`; null while queued.
     *
     * @return string|null
     */
    public function getRunId(): ?string;

    /**
     * @return bool
     */
    public function isDryRun(): bool;

    /**
     * Decoded input items; empty when the input was cleared (completed job).
     *
     * @return array<int, mixed>
     */
    public function getItems(): array;

    /**
     * @return int
     */
    public function getTotal(): int;

    /**
     * @return int
     */
    public function getOk(): int;

    /**
     * @return int
     */
    public function getFailed(): int;

    /**
     * @return int
     */
    public function getSkipped(): int;

    /**
     * @return int
     */
    public function getNextIndex(): int;

    /**
     * @return bool
     */
    public function isCancelRequested(): bool;

    /**
     * @return string|null
     */
    public function getError(): ?string;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getStartedAt(): ?string;

    /**
     * @return string|null
     */
    public function getHeartbeatAt(): ?string;

    /**
     * @return string|null
     */
    public function getFinishedAt(): ?string;
}
