<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use Magebit\Mcp\Model\Job\ConsumerRunnerProbe;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class ConsumerRunnerProbeTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function cases(): array
    {
        return [
            'missing config' => [null, true],
            'non-array config' => ['x', true],
            'empty config' => [[], true],
            'cron_run false' => [['cron_run' => false], false],
            'cron_run zero' => [['cron_run' => 0], false],
            'cron_run string zero' => [['cron_run' => '0'], false],
            'cron_run true' => [['cron_run' => true], true],
            'allow-list without ours' => [['consumers' => ['async.operations.all']], false],
            'allow-list with ours' => [['consumers' => ['x', 'magebit_mcp_job_runner']], true],
            'empty allow-list' => [['cron_run' => true, 'consumers' => []], true],
        ];
    }

    /**
     * @dataProvider cases
     * @param mixed $config
     * @param bool $expected
     * @return void
     */
    public function testIsStartedByCron(mixed $config, bool $expected): void
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->with('cron_consumers_runner')->willReturn($config);
        $this->assertSame($expected, (new ConsumerRunnerProbe($deployment))->isStartedByCron());
    }
}
