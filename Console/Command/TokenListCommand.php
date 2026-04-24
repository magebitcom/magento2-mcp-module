<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
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

    /**
     * @param TokenRepository $tokenRepository
     * @param AdminUserLookup $adminUserLookup
     */
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly AdminUserLookup $adminUserLookup
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:token:list')
            ->setDescription('List MCP tokens.')
            ->addOption(self::OPT_USER, 'u', InputOption::VALUE_REQUIRED, 'Filter by admin username.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption(self::OPT_USER);

        if (is_string($username) && $username !== '') {
            try {
                $admin = $this->adminUserLookup->getByUsername($username);
            } catch (NoSuchEntityException) {
                throw new RuntimeException(sprintf('Admin user "%s" not found.', $username));
            }
            $rawAdminId = $admin->getId();
            $adminId = is_scalar($rawAdminId) ? (int) $rawAdminId : 0;
            $tokens = $this->tokenRepository->getByAdminUserId($adminId);
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

    /**
     * Render the token's current state as a single word for the grid.
     *
     * @param Token $token
     * @return string
     */
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

    /**
     * Flatten the scope allowlist for single-cell display.
     *
     * @param Token $token
     * @return string
     */
    private function scopesOf(Token $token): string
    {
        $scopes = $token->getScopes();
        if ($scopes === null) {
            return '(all granted)';
        }
        return implode(', ', $scopes);
    }

    /**
     * Build a reverse lookup from admin-user ID to username for the token rows.
     *
     * @param Token[] $tokens
     * @return array<int, string>
     */
    private function usernamesByAdminId(array $tokens): array
    {
        $ids = [];
        foreach ($tokens as $token) {
            $ids[] = $token->getAdminUserId();
        }

        $map = [];
        foreach ($this->adminUserLookup->listByIds($ids) as $id => $user) {
            $username = (string) $user->getUsername();
            $map[$id] = $username !== '' ? $username : ('#' . $id);
        }
        return $map;
    }
}
