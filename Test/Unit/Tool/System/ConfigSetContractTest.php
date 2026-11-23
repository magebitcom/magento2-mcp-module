<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System;

use Magebit\Mcp\Model\Config\ConfigPathWriter;
use Magebit\Mcp\Model\Config\ConfigWritePolicy;
use Magebit\Mcp\Model\Config\SensitiveFieldGuard;
use Magebit\Mcp\Test\Unit\Contract\BulkToolContractTestCase;
use Magebit\Mcp\Tool\System\ConfigSet;
use Magento\Framework\App\Cache\Manager;

/**
 * system.config.set runs the shared bulk tool contract.
 */
class ConfigSetContractTest extends BulkToolContractTestCase
{
    protected function bulkToolSamples(): array
    {
        $tool = new ConfigSet(
            $this->createMock(ConfigWritePolicy::class),
            $this->createMock(SensitiveFieldGuard::class),
            $this->createMock(ConfigPathWriter::class),
            $this->createMock(Manager::class)
        );

        return [[
            'tool' => $tool,
            'sample' => [
                'path' => 'general/store_information/name',
                'value' => str_repeat('x', 50),
                'scope' => 'stores',
                'scope_code' => 'default',
            ],
        ]];
    }

    protected function declaredAclIds(): array
    {
        return array_merge(
            $this->aclIdsFromFile(dirname(__DIR__, 4) . '/etc/acl.xml'),
            ['Magento_Config::config']
        );
    }
}
