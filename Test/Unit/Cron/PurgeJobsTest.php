<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Cron;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Cron\PurgeJobs;
use Magebit\Mcp\Model\Job\JobRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PurgeJobsTest extends TestCase
{
    public function testPurgeUsesThirtyDays(): void
    {
        $repo = $this->createMock(JobRepository::class);
        $repo->expects(self::once())->method('purgeOlderThan')->with(30)->willReturn(3);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PurgeJobs($repo, $logger))->execute();
    }

    public function testFailureIsLoggedNotThrown(): void
    {
        $repo = $this->createMock(JobRepository::class);
        $repo->method('purgeOlderThan')->willThrowException(new RuntimeException('x'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new PurgeJobs($repo, $logger))->execute();
    }
}
