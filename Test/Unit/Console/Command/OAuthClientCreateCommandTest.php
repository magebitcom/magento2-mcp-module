<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Console\Command\OAuthClientCreateCommand;
use Magebit\Mcp\Model\Auth\AdminRoleLookup;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\AuthorizationOptions;
use Magebit\Mcp\Model\OAuth\AuthorizationOptionsValidator;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\OAuth\ClientPreset;
use Magebit\Mcp\Model\OAuth\ClientPresetProvider;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OAuthClientCreateCommandTest extends TestCase
{
    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * @phpstan-var ClientCredentialIssuer&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ClientCredentialIssuer&MockObject $issuer;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $adminUserLookup;

    /**
     * @phpstan-var AdminRoleLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminRoleLookup&MockObject $adminRoleLookup;

    /**
     * @phpstan-var AuthorizationOptionsValidator&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AuthorizationOptionsValidator&MockObject $validator;

    /**
     * @phpstan-var ToolRegistryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolRegistryInterface&MockObject $toolRegistry;

    /**
     * @phpstan-var PublicUrlBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private PublicUrlBuilder&MockObject $urlBuilder;

    private ClientPresetProvider $presetProvider;

    protected function setUp(): void
    {
        $this->issuer = $this->createMock(ClientCredentialIssuer::class);
        $this->adminUserLookup = $this->createMock(AdminUserLookup::class);
        $this->adminRoleLookup = $this->createMock(AdminRoleLookup::class);
        $this->validator = $this->createMock(AuthorizationOptionsValidator::class);
        $this->toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $this->urlBuilder = $this->createMock(PublicUrlBuilder::class);
        $this->presetProvider = new ClientPresetProvider([
            new ClientPreset('claude_web', 'Claude Web', 'Claude Web', [self::CALLBACK]),
        ]);

        $this->toolRegistry->method('all')->willReturn([
            'catalog.product.list' => $this->createMock(ToolInterface::class),
            'sales.order.get' => $this->createMock(ToolInterface::class),
        ]);
        $this->urlBuilder->method('getResourceUrl')->willReturn('https://shop.test/mcp');
        $this->validator->method('validate')->willReturn(null);
    }

    public function testCreatesAClientAndPrintsTheSecretOnce(): void
    {
        $this->expectIssue(
            name: 'Claude Web',
            redirectUris: [self::CALLBACK],
            allowedTools: ['catalog.product.list']
        );

        $tester = $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--tool' => ['catalog.product.list'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('generated-client-id', $display);
        self::assertStringContainsString('generated-secret', $display);
        self::assertStringContainsString('will not be shown again', $display);
    }

    public function testPrintsTheMcpEndpointSoTheClientCanBeConfigured(): void
    {
        $this->expectIssue('Claude Web', [self::CALLBACK], ['catalog.product.list']);

        $tester = $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--tool' => ['catalog.product.list'],
        ]);

        self::assertStringContainsString('https://shop.test/mcp', $tester->getDisplay());
    }

    public function testPresetSuppliesNameAndRedirectUri(): void
    {
        $this->expectIssue('Claude Web', [self::CALLBACK], ['catalog.product.list']);

        $tester = $this->runCommand([
            '--preset' => 'claude_web',
            '--tool' => ['catalog.product.list'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExplicitNameOverridesThePresetName(): void
    {
        $this->expectIssue('Staging connector', [self::CALLBACK], ['catalog.product.list']);

        $tester = $this->runCommand([
            '--preset' => 'claude_web',
            '--name' => 'Staging connector',
            '--tool' => ['catalog.product.list'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testUnknownPresetIsRejectedAndTheKnownOnesAreListed(): void
    {
        $this->expectExceptionMessageMatches('/claude_web/');
        $this->expectException(RuntimeException::class);

        $this->runCommand(['--preset' => 'nope', '--tool' => ['catalog.product.list']]);
    }

    public function testListPresetsPrintsEveryPresetAndCreatesNothing(): void
    {
        $this->issuer->expects(self::never())->method('issue');

        $tester = $this->runCommand(['--list-presets' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('claude_web', $tester->getDisplay());
    }

    public function testAllowAllToolsStoresTheSentinel(): void
    {
        $this->expectIssue('Claude Web', [self::CALLBACK], [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL]);

        $tester = $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testMissingToolSelectionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/--tool|--allow-all-tools/');

        $this->runCommand(['--name' => 'Claude Web', '--redirect-uri' => [self::CALLBACK]]);
    }

    public function testUnregisteredToolNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/catalog\.product\.nope/');

        $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--tool' => ['catalog.product.nope'],
        ]);
    }

    public function testMissingRedirectUriIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/--redirect-uri/');

        $this->runCommand(['--name' => 'Claude Web', '--tool' => ['catalog.product.list']]);
    }

    public function testMissingNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/--name/');

        $this->runCommand(['--redirect-uri' => [self::CALLBACK], '--tool' => ['catalog.product.list']]);
    }

    public function testSharedModeResolvesTheServiceAdminUsernameToAnId(): void
    {
        $this->adminUserLookup->method('getByUsername')->with('svc')->willReturn($this->makeUser(12));

        $this->issuer->expects(self::once())->method('issue')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::callback(static fn (AuthorizationOptions $o): bool => $o->mode === AuthMode::SHARED
                && $o->serviceAdminUserId === 12)
        )->willReturn($this->issued());

        $tester = $this->runCommand([
            '--name' => 'Shared connector',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--auth-mode' => 'shared',
            '--service-admin-user' => 'svc',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testSharedModeWithoutAServiceAdminIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/--service-admin-user/');

        $this->runCommand([
            '--name' => 'Shared connector',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--auth-mode' => 'shared',
        ]);
    }

    public function testUnknownAuthModeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/personal/');

        $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--auth-mode' => 'sideways',
        ]);
    }

    public function testUnknownAdminUsernameIsRejected(): void
    {
        $this->adminUserLookup->method('getByUsername')
            ->willThrowException(NoSuchEntityException::singleField('username', 'ghost'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ghost/');

        $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--allowed-admin-user' => ['ghost'],
        ]);
    }

    public function testWhitelistsResolveUsernamesAndRoleNamesToIds(): void
    {
        $this->adminUserLookup->method('getByUsername')->with('alice')->willReturn($this->makeUser(3));
        $this->adminRoleLookup->method('getIdByName')->with('Support')->willReturn(7);

        $this->issuer->expects(self::once())->method('issue')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::callback(static fn (AuthorizationOptions $o): bool => $o->allowedAdminUserIds === [3]
                && $o->allowedAdminRoleIds === [7])
        )->willReturn($this->issued());

        $tester = $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--allowed-admin-user' => ['alice'],
            '--allowed-admin-role' => ['Support'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testUnknownRoleNameIsRejected(): void
    {
        $this->adminRoleLookup->method('getIdByName')
            ->willThrowException(NoSuchEntityException::singleField('role_name', 'Ghosts'));
        $this->adminRoleLookup->method('getAllNames')->willReturn(['Administrators', 'Support']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ghosts/');

        $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--allowed-admin-role' => ['Ghosts'],
        ]);
    }

    public function testDisabledFlagIsPassedThrough(): void
    {
        $this->issuer->expects(self::once())->method('issue')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::callback(static fn (AuthorizationOptions $o): bool => $o->disabled === true)
        )->willReturn($this->issued());

        $tester = $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--disabled' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testValidatorRejectionAbortsBeforeIssuing(): void
    {
        $validator = $this->createMock(AuthorizationOptionsValidator::class);
        $validator->method('validate')->willReturn('Service admin must be active.');
        $this->validator = $validator;
        $this->adminUserLookup->method('getByUsername')->willReturn($this->makeUser(12));
        $this->issuer->expects(self::never())->method('issue');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be active/');

        $this->runCommand([
            '--name' => 'Shared connector',
            '--redirect-uri' => [self::CALLBACK],
            '--allow-all-tools' => true,
            '--auth-mode' => 'shared',
            '--service-admin-user' => 'svc',
        ]);
    }

    public function testInvalidRedirectUriFromTheIssuerSurfacesAsAnError(): void
    {
        $this->issuer->method('issue')->willThrowException(
            new \InvalidArgumentException('Redirect URIs must be absolute HTTPS URIs.')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/absolute HTTPS/');

        $this->runCommand([
            '--name' => 'Claude Web',
            '--redirect-uri' => ['ftp://nope'],
            '--allow-all-tools' => true,
        ]);
    }

    public function testNoOptionClaimsAShortcutSymfonyReservesGlobally(): void
    {
        // Magento's console application registers -h -q -v -V -n -e, and Symfony
        // aborts the whole CLI when a command re-uses one.
        $reserved = ['h', 'q', 'v', 'V', 'n', 'e'];

        foreach ($this->makeCommand()->getDefinition()->getOptions() as $option) {
            $shortcut = $option->getShortcut();
            if ($shortcut === null) {
                continue;
            }
            self::assertNotContains(
                $shortcut,
                $reserved,
                sprintf('--%s must not use the reserved -%s shortcut.', $option->getName(), $shortcut)
            );
        }
    }

    /**
     * @param string $name
     * @param array<int, string> $redirectUris
     * @param array<int, string> $allowedTools
     * @return void
     */
    private function expectIssue(string $name, array $redirectUris, array $allowedTools): void
    {
        $this->issuer->expects(self::once())
            ->method('issue')
            ->with($name, $redirectUris, $allowedTools, self::anything())
            ->willReturn($this->issued());
    }

    /**
     * @return array{client: Client, client_id: string, client_secret: string}
     */
    private function issued(): array
    {
        $client = $this->createMock(Client::class);
        $client->method('getId')->willReturn(5);
        return [
            'client' => $client,
            'client_id' => 'generated-client-id',
            'client_secret' => 'generated-secret',
        ];
    }

    /**
     * @param int $id
     * @return User&MockObject
     */
    private function makeUser(int $id): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    /**
     * @param array<string, mixed> $input
     * @return CommandTester
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->makeCommand());
        $tester->execute($input);
        return $tester;
    }

    /**
     * @return OAuthClientCreateCommand
     */
    private function makeCommand(): OAuthClientCreateCommand
    {
        return new OAuthClientCreateCommand(
            $this->issuer,
            $this->adminUserLookup,
            $this->adminRoleLookup,
            $this->validator,
            $this->toolRegistry,
            $this->presetProvider,
            $this->urlBuilder
        );
    }
}
