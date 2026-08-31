<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use InvalidArgumentException;
use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Api\OAuth\ClientPresetProviderInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Auth\AdminRoleLookup;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\AuthorizationOptions;
use Magebit\Mcp\Model\OAuth\AuthorizationOptionsValidator;
use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
use Magebit\Mcp\Model\Url\PublicUrlBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `bin/magento magebit:mcp:oauth:client:create` — register an OAuth 2.1 client.
 *
 * Same code path as System → MCP → OAuth Clients → Add. The client secret is
 * printed once and only its hash is persisted, so it cannot be read back.
 */
class OAuthClientCreateCommand extends Command
{
    private const OPT_NAME = 'name';
    private const OPT_PRESET = 'preset';
    private const OPT_LIST_PRESETS = 'list-presets';
    private const OPT_REDIRECT_URI = 'redirect-uri';
    private const OPT_TOOL = 'tool';
    private const OPT_ALLOW_ALL_TOOLS = 'allow-all-tools';
    private const OPT_AUTH_MODE = 'auth-mode';
    private const OPT_SERVICE_ADMIN = 'service-admin-user';
    private const OPT_ALLOWED_ADMIN_USER = 'allowed-admin-user';
    private const OPT_ALLOWED_ADMIN_ROLE = 'allowed-admin-role';
    private const OPT_DISABLED = 'disabled';

    /**
     * @param ClientCredentialIssuer $issuer
     * @param AdminUserLookup $adminUserLookup
     * @param AdminRoleLookup $adminRoleLookup
     * @param AuthorizationOptionsValidator $authorizationValidator
     * @param ToolRegistryInterface $toolRegistry
     * @param ClientPresetProviderInterface $presetProvider
     * @param PublicUrlBuilder $urlBuilder
     */
    public function __construct(
        private readonly ClientCredentialIssuer $issuer,
        private readonly AdminUserLookup $adminUserLookup,
        private readonly AdminRoleLookup $adminRoleLookup,
        private readonly AuthorizationOptionsValidator $authorizationValidator,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ClientPresetProviderInterface $presetProvider,
        private readonly PublicUrlBuilder $urlBuilder
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:oauth:client:create')
            ->setDescription(
                'Register an OAuth 2.1 client for the MCP server. The client secret is shown once.'
            )
            ->addOption(
                self::OPT_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'Operator-facing label (e.g. "Claude Web, production").'
            )
            ->addOption(
                self::OPT_PRESET,
                'p',
                InputOption::VALUE_REQUIRED,
                'Fill name and redirect URI from a known AI client preset. See --list-presets.'
            )
            ->addOption(
                self::OPT_LIST_PRESETS,
                null,
                InputOption::VALUE_NONE,
                'Print the available presets and exit without creating anything.'
            )
            ->addOption(
                self::OPT_REDIRECT_URI,
                'r',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Allowed redirect URI, matched byte-for-byte (repeatable). HTTPS, or http://localhost.'
            )
            ->addOption(
                self::OPT_TOOL,
                't',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Tool name this client may request at consent time (repeatable).'
            )
            ->addOption(
                self::OPT_ALLOW_ALL_TOOLS,
                null,
                InputOption::VALUE_NONE,
                'Let the client request every registered tool, including ones added later.'
            )
            ->addOption(
                self::OPT_AUTH_MODE,
                null,
                InputOption::VALUE_REQUIRED,
                'personal (each admin consents for themselves) or shared (all consents bound to one admin).',
                AuthMode::PERSONAL->value
            )
            ->addOption(
                self::OPT_SERVICE_ADMIN,
                null,
                InputOption::VALUE_REQUIRED,
                'Admin username every issued token is bound to. Required with --auth-mode=shared.'
            )
            ->addOption(
                self::OPT_ALLOWED_ADMIN_USER,
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Restrict consent to this admin username (repeatable, personal mode only).'
            )
            ->addOption(
                self::OPT_ALLOWED_ADMIN_ROLE,
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Restrict consent to this admin role name (repeatable, personal mode only).'
            )
            ->addOption(
                self::OPT_DISABLED,
                null,
                InputOption::VALUE_NONE,
                'Create the client switched off — it cannot mint or refresh tokens until enabled.'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption(self::OPT_LIST_PRESETS)) {
            $this->printPresets($output);
            return Command::SUCCESS;
        }

        $preset = $this->resolvePreset($input);

        $name = $this->optionalString($input->getOption(self::OPT_NAME))
            ?? ($preset !== null && $preset->getName() !== '' ? $preset->getName() : null);
        if ($name === null) {
            throw new RuntimeException('--name is required (or use --preset to take the preset name).');
        }

        $redirectUris = $this->stringList($input->getOption(self::OPT_REDIRECT_URI));
        if ($preset !== null) {
            $redirectUris = array_values(array_unique(array_merge($preset->getRedirectUris(), $redirectUris)));
        }
        if ($redirectUris === []) {
            throw new RuntimeException('At least one --redirect-uri is required.');
        }

        $allowedTools = $this->resolveAllowedTools($input);
        $auth = $this->resolveAuthorizationOptions($input);

        $error = $this->authorizationValidator->validate($auth);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        try {
            $issued = $this->issuer->issue($name, $redirectUris, $allowedTools, $auth);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to create the OAuth client: %s', $e->getMessage()),
                0,
                $e
            );
        }

        $this->printResult($output, $name, $issued['client_id'], $issued['client_secret'], $allowedTools, $auth);

        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return \Magebit\Mcp\Api\Data\OAuth\ClientPresetInterface|null
     * @throws RuntimeException
     */
    private function resolvePreset(InputInterface $input): ?\Magebit\Mcp\Api\Data\OAuth\ClientPresetInterface
    {
        $id = $this->optionalString($input->getOption(self::OPT_PRESET));
        if ($id === null) {
            return null;
        }

        foreach ($this->presetProvider->getAll() as $preset) {
            if ($preset->getId() === $id) {
                return $preset;
            }
        }

        throw new RuntimeException(sprintf(
            'Unknown preset "%s". Available: %s.',
            $id,
            implode(', ', $this->presetIds())
        ));
    }

    /**
     * @param OutputInterface $output
     * @return void
     */
    private function printPresets(OutputInterface $output): void
    {
        $output->writeln('<info>Available OAuth client presets:</info>');
        foreach ($this->presetProvider->getAll() as $preset) {
            $uris = $preset->getRedirectUris();
            $output->writeln(sprintf(
                '  %-14s %s',
                $preset->getId(),
                $uris === [] ? $preset->getLabel() : implode(' ', $uris)
            ));
        }
    }

    /**
     * @return array<int, string>
     */
    private function presetIds(): array
    {
        $ids = [];
        foreach ($this->presetProvider->getAll() as $preset) {
            $ids[] = $preset->getId();
        }
        return $ids;
    }

    /**
     * @param InputInterface $input
     * @return array<int, string>
     * @throws RuntimeException
     */
    private function resolveAllowedTools(InputInterface $input): array
    {
        $allowAll = (bool) $input->getOption(self::OPT_ALLOW_ALL_TOOLS);
        $tools = $this->stringList($input->getOption(self::OPT_TOOL));

        if ($allowAll) {
            if ($tools !== []) {
                throw new RuntimeException('--allow-all-tools and --tool are mutually exclusive.');
            }
            return [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL];
        }

        if ($tools === []) {
            throw new RuntimeException(
                'Pick the tools this client may request: repeat --tool, or pass --allow-all-tools.'
            );
        }

        $registered = $this->toolRegistry->all();
        $unknown = [];
        foreach ($tools as $tool) {
            if (!isset($registered[$tool])) {
                $unknown[] = $tool;
            }
        }
        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                'Unknown tool(s): %s. Run magebit:mcp:tools:list to see what is registered.',
                implode(', ', $unknown)
            ));
        }

        return $tools;
    }

    /**
     * @param InputInterface $input
     * @return AuthorizationOptions
     * @throws RuntimeException
     */
    private function resolveAuthorizationOptions(InputInterface $input): AuthorizationOptions
    {
        $modeRaw = $this->optionalString($input->getOption(self::OPT_AUTH_MODE)) ?? AuthMode::PERSONAL->value;
        $mode = AuthMode::tryFrom($modeRaw);
        if ($mode === null) {
            throw new RuntimeException(sprintf(
                'Unknown --auth-mode "%s". Use "%s" or "%s".',
                $modeRaw,
                AuthMode::PERSONAL->value,
                AuthMode::SHARED->value
            ));
        }

        $serviceAdminName = $this->optionalString($input->getOption(self::OPT_SERVICE_ADMIN));
        if ($mode === AuthMode::SHARED && $serviceAdminName === null) {
            throw new RuntimeException(
                '--auth-mode=shared requires --service-admin-user: the admin every issued token is bound to.'
            );
        }
        if ($mode === AuthMode::PERSONAL && $serviceAdminName !== null) {
            throw new RuntimeException('--service-admin-user only applies to --auth-mode=shared.');
        }

        $allowedUsernames = $this->stringList($input->getOption(self::OPT_ALLOWED_ADMIN_USER));
        $allowedRoleNames = $this->stringList($input->getOption(self::OPT_ALLOWED_ADMIN_ROLE));
        if ($mode === AuthMode::SHARED && ($allowedUsernames !== [] || $allowedRoleNames !== [])) {
            throw new RuntimeException(
                'Admin whitelists only apply to --auth-mode=personal; shared mode pins one service admin instead.'
            );
        }

        return new AuthorizationOptions(
            mode: $mode,
            serviceAdminUserId: $serviceAdminName === null ? null : $this->adminUserId($serviceAdminName),
            allowedAdminUserIds: array_map([$this, 'adminUserId'], $allowedUsernames),
            allowedAdminRoleIds: array_map([$this, 'adminRoleId'], $allowedRoleNames),
            disabled: (bool) $input->getOption(self::OPT_DISABLED)
        );
    }

    /**
     * @param string $username
     * @return int
     * @throws RuntimeException
     */
    private function adminUserId(string $username): int
    {
        try {
            $user = $this->adminUserLookup->getByUsername($username);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('Admin user "%s" not found.', $username));
        }
        $raw = $user->getId();
        return is_scalar($raw) ? (int) $raw : 0;
    }

    /**
     * @param string $roleName
     * @return int
     * @throws RuntimeException
     */
    private function adminRoleId(string $roleName): int
    {
        try {
            return $this->adminRoleLookup->getIdByName($roleName);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf(
                'Admin role "%s" not found. Available: %s.',
                $roleName,
                implode(', ', $this->adminRoleLookup->getAllNames())
            ));
        }
    }

    /**
     * @param OutputInterface $output
     * @param string $name
     * @param string $clientId
     * @param string $clientSecret
     * @param array<int, string> $allowedTools
     * @param AuthorizationOptions $auth
     * @return void
     */
    private function printResult(
        OutputInterface $output,
        string $name,
        string $clientId,
        string $clientSecret,
        array $allowedTools,
        AuthorizationOptions $auth
    ): void {
        $output->writeln(sprintf('<info>OAuth client "%s" registered.</info>', $name));
        $output->writeln(sprintf('<comment>Auth mode:</comment> %s', $auth->mode->value));
        $output->writeln(sprintf(
            '<comment>Requestable tools:</comment> %s',
            $allowedTools === [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL]
                ? 'all registered tools'
                : implode(', ', $allowedTools)
        ));
        if ($auth->disabled) {
            $output->writeln('<comment>Status:</comment> disabled — enable it before connecting a client.');
        }
        $output->writeln('');
        $output->writeln(sprintf('<comment>MCP endpoint:</comment>  %s', $this->urlBuilder->getResourceUrl()));
        $output->writeln(sprintf('<comment>Client ID:</comment>     %s', $clientId));
        $output->writeln('');
        $output->writeln('<comment>Client secret — store it now, it will not be shown again:</comment>');
        $output->writeln($clientSecret);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return array_values(array_unique($out));
    }
}
