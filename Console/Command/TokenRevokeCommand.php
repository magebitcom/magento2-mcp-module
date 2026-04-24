<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:token:revoke <id>` — stamps revoked_at = now.
 * The token row stays in the DB so the audit log can still reference it.
 * To remove entirely, use `magebit:mcp:token:delete`.
 */
class TokenRevokeCommand extends Command
{
    private const ARG_ID = 'id';

    /**
     * @param TokenRepository $tokenRepository
     */
    public function __construct(
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:token:revoke')
            ->setDescription(
                'Revoke an MCP token by id (audit-preserving — use token:delete to also remove the row).'
            )
            ->addArgument(self::ARG_ID, InputArgument::REQUIRED, 'Token id (numeric).');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument(self::ARG_ID);
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new RuntimeException('Token id must be a positive integer.');
        }
        $id = (int) $raw;

        try {
            $token = $this->tokenRepository->revoke($id);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('Token %d not found.', $id));
        }

        $output->writeln(sprintf('<info>Token %d revoked at %s (UTC).</info>', $id, $token->getRevokedAt() ?? '?'));

        return Command::SUCCESS;
    }
}
