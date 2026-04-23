<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\User\Model\UserFactory;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:token:list` — dump every MCP token (or filter by admin).
 */
class TokenListCommand extends Command
{
    private const OPT_USER = 'admin-user';

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly UserFactory $userFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magebit:mcp:token:list')
            ->setDescription('List MCP tokens.')
            ->addOption(self::OPT_USER, 'u', InputOption::VALUE_REQUIRED, 'Filter by admin username.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption(self::OPT_USER);

        if (is_string($username) && $username !== '') {
            $admin = $this->userFactory->create();
            $admin->loadByUsername($username);
            $adminId = $admin->getId();
            if (!is_scalar($adminId) || (int) $adminId === 0) {
                throw new RuntimeException(sprintf('Admin user "%s" not found.', $username));
            }
            $tokens = $this->tokenRepository->getByAdminUserId((int) $adminId);
        } else {
            $tokens = $this->tokenRepository->getList();
        }

        if ($tokens === []) {
            $output->writeln('<comment>No MCP tokens found.</comment>');
            return Command::SUCCESS;
        }

        $usernames = $this->usernamesByAdminId($tokens);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Admin', 'Name', 'Status', 'Writes', 'Scopes', 'Last used', 'Expires', 'Created']);
        foreach ($tokens as $token) {
            $table->addRow([
                $token->getId() ?? 0,
                $usernames[$token->getAdminUserId()] ?? ('#' . $token->getAdminUserId()),
                $token->getName(),
                $this->statusOf($token),
                $token->getAllowWrites() ? 'yes' : 'no',
                $this->scopesOf($token),
                $token->getLastUsedAt() ?? '-',
                $token->getExpiresAt() ?? '-',
                $token->getCreatedAt() ?? '-',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function statusOf(Token $token): string
    {
        if ($token->isRevoked()) {
            return 'revoked';
        }
        if ($token->isExpired()) {
            return 'expired';
        }
        return 'active';
    }

    private function scopesOf(Token $token): string
    {
        $scopes = $token->getScopes();
        if ($scopes === null) {
            return '(all granted)';
        }
        return implode(', ', $scopes);
    }

    /**
     * @param array<int, Token> $tokens
     * @return array<int, string>
     */
    private function usernamesByAdminId(array $tokens): array
    {
        $ids = [];
        foreach ($tokens as $token) {
            $ids[$token->getAdminUserId()] = true;
        }

        $map = [];
        foreach (array_keys($ids) as $id) {
            $user = $this->userFactory->create();
            // @phpstan-ignore-next-line magento.serviceContract — User module ships no repository for admin users.
            $user->load($id);
            if ($user->getId() === null) {
                continue;
            }
            $username = (string) $user->getUsername();
            $map[$id] = $username !== '' ? $username : ('#' . $id);
        }
        return $map;
    }
}
