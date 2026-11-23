<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Job;

use Magento\Framework\App\DeploymentConfig;

/**
 * Tells whether Magento's cron will start the `magebit_mcp_job_runner` consumer on this store.
 */
class ConsumerRunnerProbe
{
    public const CONSUMER = 'magebit_mcp_job_runner';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    /**
     * @return bool
     */
    public function isStartedByCron(): bool
    {
        $config = $this->deploymentConfig->get('cron_consumers_runner');
        if (!is_array($config)) {
            return true;
        }
        if (array_key_exists('cron_run', $config) && !$config['cron_run']) {
            return false;
        }
        $consumers = $config['consumers'] ?? [];
        if (is_array($consumers) && $consumers !== [] && !in_array(self::CONSUMER, $consumers, true)) {
            return false;
        }
        return true;
    }
}
