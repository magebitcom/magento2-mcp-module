<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Job;

use Magebit\Mcp\Api\Data\JobInterface;
use Magebit\Mcp\Exception\ForbiddenException;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Config\FreshDefaultConfigReader;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Job\JobAccessGuard;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\ToolAvailability;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Review lap 1 #2: a long-lived consumer must see config changes made after it started.
 */
class JobAccessGuardTest extends TestCase
{
    private const TOOL = 'catalog.product.update';

    /**
     * @var array<string, string|null> Default-scope values the fresh reader returns right now.
     */
    private array $values = [];

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function flipProvider(): array
    {
        return [
            'server switched off' => [
                ModuleConfig::XML_PATH_ENABLED,
                '0',
                'The MCP server is turned off in the store configuration.',
            ],
            'store writes closed' => [
                ModuleConfig::XML_PATH_ALLOW_WRITES,
                '0',
                'Write tools are switched off for this store (setting magebit_mcp/general/allow_writes); '
                    . 'the store owner can turn them on.',
            ],
            'tool disabled' => [
                DisabledTools::XML_PATH_DISABLED_TOOLS,
                "system.cache.flush\r\n" . self::TOOL . "\n",
                'The tool catalog_product_update is no longer available on this store.',
            ],
        ];
    }

    /**
     * @dataProvider flipProvider
     * @param string $path
     * @param string|null $value
     * @param string $expected
     * @return void
     */
    public function testValueChangedBetweenTwoClaimsIsSeen(string $path, ?string $value, string $expected): void
    {
        $this->values = [
            ModuleConfig::XML_PATH_ENABLED => '1',
            ModuleConfig::XML_PATH_ALLOW_WRITES => '1',
            DisabledTools::XML_PATH_DISABLED_TOOLS => 'system.cache.flush',
        ];
        $guard = $this->guard();

        $guard->authorize($this->job());
        $this->values[$path] = $value;

        try {
            $guard->authorize($this->job());
            self::fail('The second claim was not refused.');
        } catch (ForbiddenException $e) {
            self::assertSame($expected, $e->getMessage());
        }
    }

    /**
     * @return JobAccessGuard
     */
    private function guard(): JobAccessGuard
    {
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('getAclResource')->willReturn('Vendor_Module::mcp_tool_catalog_product_update');
        $adapter->method('getUnderlyingAclResource')->willReturn('Magento_Catalog::products');
        $adapter->method('getWriteMode')->willReturn(WriteMode::WRITE);
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('get')->willReturn($adapter);
        $availability = $this->createMock(ToolAvailability::class);
        $availability->method('isAvailable')->willReturn(true);
        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturn(true);
        $reader = $this->createMock(FreshDefaultConfigReader::class);
        $reader->method('get')->willReturnCallback(fn (string $path): ?string => $this->values[$path] ?? null);
        $reader->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => (bool) ($this->values[$path] ?? null)
        );
        $token = $this->createMock(Token::class);
        $token->method('getAllowWrites')->willReturn(true);
        $tokens = $this->createMock(TokenRepository::class);
        $tokens->method('getById')->willReturn($token);
        $admin = $this->createMock(User::class);
        $admin->method('getIsActive')->willReturn('1');
        $admins = $this->createMock(AdminUserLookup::class);
        $admins->method('getById')->willReturn($admin);

        return new JobAccessGuard(
            $registry,
            $availability,
            $this->createMock(AclBuilder::class),
            $aclChecker,
            $reader,
            $tokens,
            $admins,
            $this->createMock(ClientRepository::class)
        );
    }

    /**
     * @return JobInterface
     */
    private function job(): JobInterface
    {
        $job = $this->createMock(JobInterface::class);
        $job->method('getToolName')->willReturn(self::TOOL);
        $job->method('getAdminUserId')->willReturn(7);
        $job->method('getTokenId')->willReturn(3);
        $job->method('getOauthClientId')->willReturn(null);
        return $job;
    }
}
