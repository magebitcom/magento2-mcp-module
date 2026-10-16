<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Api\Security;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\JsonRpc\ErrorCode;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Test\Api\McpTestCase;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * A read-only token cannot run any write tool. `adminUser` has full admin rights,
 * so the write gate is the first check that can refuse the call.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 */
class AllWriteToolsGatedTest extends McpTestCase
{
    protected ?bool $allowWrites = false;

    /**
     * @return void
     */
    public function testEveryWriteToolIsRefusedWithWriteNotAllowed(): void
    {
        $om = Bootstrap::getObjectManager();
        $disabled = $om->get(DisabledTools::class);
        $misses = [];
        $checked = 0;
        foreach ($om->get(ToolRegistryInterface::class)->all() as $tool) {
            $name = $tool->getName();
            if ($tool->getWriteMode() !== WriteMode::WRITE || $disabled->isDisabled($name)) {
                continue;
            }
            $code = $this->toolsCall($name, [])['body']['error']['code'] ?? null;
            $audit = AuditLogReader::latest($name, $this->tokenRowId);
            if ($code !== ErrorCode::WRITE_NOT_ALLOWED->value || ($audit['error_code'] ?? null) !== '-32012') {
                $misses[] = $name . ' => ' . var_export($code, true)
                    . ' (audit ' . var_export($audit['error_code'] ?? null, true) . ')';
            }
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
        self::assertSame([], $misses, 'Write tools not stopped by the write gate');
    }
}
