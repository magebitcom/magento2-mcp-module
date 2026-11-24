<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Model\ResourceModel\Job as JobResource;
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

/**
 * Read model of a background job row. Persistence goes through {@see JobRepository}.
 */
class Job extends AbstractModel implements JobInterface
{
    use ReturnsStrictTypedData;

    protected function _construct(): void
    {
        $this->_init(JobResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getJobId(): string
    {
        return $this->getDataString(self::JOB_ID);
    }

    /**
     * @inheritDoc
     */
    public function getToolName(): string
    {
        return $this->getDataString(self::TOOL_NAME);
    }

    /**
     * @inheritDoc
     */
    public function getAdminUserId(): int
    {
        return $this->getDataInt(self::ADMIN_USER_ID, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getTokenId(): ?int
    {
        return $this->getDataIntOrNull(self::TOKEN_ID, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getOauthClientId(): ?int
    {
        return $this->getDataIntOrNull(self::OAUTH_CLIENT_ID, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getState(): string
    {
        return $this->getDataString(self::STATE);
    }

    /**
     * @inheritDoc
     */
    public function getRunId(): ?string
    {
        return $this->getDataStringOrNull(self::RUN_ID);
    }

    /**
     * @inheritDoc
     */
    public function isDryRun(): bool
    {
        return $this->getDataInt(self::DRY_RUN, cast: true) === 1;
    }

    /**
     * @inheritDoc
     */
    public function getItems(): array
    {
        $json = $this->getDataStringOrNull(self::ITEMS_JSON);
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @inheritDoc
     */
    public function getTotal(): int
    {
        return $this->getDataInt(self::TOTAL, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getOk(): int
    {
        return $this->getDataInt(self::OK, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getFailed(): int
    {
        return $this->getDataInt(self::FAILED, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getSkipped(): int
    {
        return $this->getDataInt(self::SKIPPED, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function getNextIndex(): int
    {
        return $this->getDataInt(self::NEXT_INDEX, cast: true);
    }

    /**
     * @inheritDoc
     */
    public function isCancelRequested(): bool
    {
        return $this->getDataInt(self::CANCEL_REQUESTED, cast: true) === 1;
    }

    /**
     * @inheritDoc
     */
    public function getError(): ?string
    {
        return $this->getDataStringOrNull(self::ERROR);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->getDataStringOrNull(self::CREATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function getStartedAt(): ?string
    {
        return $this->getDataStringOrNull(self::STARTED_AT);
    }

    /**
     * @inheritDoc
     */
    public function getHeartbeatAt(): ?string
    {
        return $this->getDataStringOrNull(self::HEARTBEAT_AT);
    }

    /**
     * @inheritDoc
     */
    public function getFinishedAt(): ?string
    {
        return $this->getDataStringOrNull(self::FINISHED_AT);
    }
}
