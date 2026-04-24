<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:token:create` — mint a bearer for an admin user.
 *
 * Plaintext is printed to stdout once; only the hash is persisted, so it
 * cannot be retrieved afterwards.
 */
class TokenCreateCommand extends Command
{
    private const OPT_USER = 'admin-user';
    private const OPT_NAME = 'name';
    private const OPT_ALLOW_WRITES = 'allow-writes';
    private const OPT_EXPIRES = 'expires';
    private const OPT_SCOPE = 'scope';

    public function __construct(
        private readonly AdminUserLookup $adminUserLookup,
        private readonly TokenFactory $tokenFactory,
        private readonly TokenGenerator $tokenGenerator,
        private readonly TokenHasher $tokenHasher,
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magebit:mcp:token:create')
            ->setDescription('Mint an MCP bearer token for an admin user. The plaintext is shown once.')
            ->addOption(
                self::OPT_USER,
                'u',
                InputOption::VALUE_REQUIRED,
                'Admin username owning this token.'
            )
            ->addOption(
                self::OPT_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'Human-readable label (e.g. "Claude Desktop, laptop").'
            )
            ->addOption(
                self::OPT_ALLOW_WRITES,
                null,
                InputOption::VALUE_NONE,
                'Permit WRITE tools (global kill-switch must also be enabled).'
            )
            ->addOption(
                self::OPT_EXPIRES,
                null,
                InputOption::VALUE_REQUIRED,
                'Expiration timestamp in strtotime-parseable format (e.g. "+30 days").'
            )
            ->addOption(
                self::OPT_SCOPE,
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Tool name the token is scoped to (repeatable). Empty means all tools the admin role grants.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->requiredString($input->getOption(self::OPT_USER), '--admin-user');
        $name = $this->requiredString($input->getOption(self::OPT_NAME), '--name');
        $allowWrites = (bool) $input->getOption(self::OPT_ALLOW_WRITES);
        $expiresRaw = $input->getOption(self::OPT_EXPIRES);
        $scopes = $input->getOption(self::OPT_SCOPE);
        if (!is_array($scopes)) {
            $scopes = [];
        }
        /** @var array<int, string> $scopeList */
        $scopeList = array_values(array_filter(
            $scopes,
            static fn($s): bool => is_string($s) && $s !== ''
        ));

        $expiresAt = null;
        if (is_string($expiresRaw) && $expiresRaw !== '') {
            try {
                $dt = new \DateTimeImmutable($expiresRaw, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new RuntimeException(sprintf(
                    'Unable to parse --expires value "%s": %s',
                    $expiresRaw,
                    $e->getMessage()
                ));
            }
            $expiresAt = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        try {
            $admin = $this->adminUserLookup->getByUsername($username);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('Admin user "%s" not found.', $username));
        }
        $rawAdminId = $admin->getId();
        $adminId = is_scalar($rawAdminId) ? (int) $rawAdminId : 0;

        $plaintext = $this->tokenGenerator->generate();
        $hash = $this->tokenHasher->hash($plaintext);

        $token = $this->tokenFactory->create();
        $token->setAdminUserId($adminId);
        $token->setName($name);
        $token->setTokenHash($hash);
        $token->setAllowWrites($allowWrites);
        $token->setScopes($scopeList === [] ? null : $scopeList);
        if ($expiresAt !== null) {
            $token->setExpiresAt($expiresAt);
        }

        $this->tokenRepository->save($token);

        $output->writeln(sprintf(
            '<info>Token minted — id=%d, admin=%s, name=%s.</info>',
            $token->getId() ?? 0,
            $username,
            $name
        ));
        if ($expiresAt !== null) {
            $output->writeln(sprintf('<comment>Expires at (UTC): %s</comment>', $expiresAt));
        }
        if ($scopeList !== []) {
            $output->writeln(sprintf('<comment>Scoped to tools: %s</comment>', implode(', ', $scopeList)));
        }
        if ($allowWrites) {
            $output->writeln('<comment>Allow-writes: ON (global kill-switch must also be enabled).</comment>');
        }
        $output->writeln('');
        $output->writeln('<comment>Bearer token — store it securely, it will not be shown again:</comment>');
        $output->writeln($plaintext);

        return Command::SUCCESS;
    }

    private function requiredString(mixed $value, string $flag): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('%s is required.', $flag));
        }
        return $value;
    }
}
