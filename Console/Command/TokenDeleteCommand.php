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
 * `bin/magento magebit:mcp:token:delete <id>` — hard-remove a token row.
 *
 * Audit rows survive via FK ON DELETE SET NULL. Prefer `token:revoke` for
 * day-to-day use — deletion is for housekeeping / GDPR requests.
 */
class TokenDeleteCommand extends Command
{
    private const ARG_ID = 'id';

    public function __construct(
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magebit:mcp:token:delete')
            ->setDescription(
                'Delete an MCP token by id. Prefer magebit:mcp:token:revoke for audit-preserving invalidation.'
            )
            ->addArgument(self::ARG_ID, InputArgument::REQUIRED, 'Token id (numeric).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument(self::ARG_ID);
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new RuntimeException('Token id must be a positive integer.');
        }
        $id = (int) $raw;

        try {
            $this->tokenRepository->deleteById($id);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('Token %d not found.', $id));
        }

        $output->writeln(sprintf('<info>Token %d deleted.</info>', $id));

        return Command::SUCCESS;
    }
}
