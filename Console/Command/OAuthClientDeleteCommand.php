<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `bin/magento magebit:mcp:oauth:client:delete <id>` — remove a client row.
 *
 * The repository revokes the client's access tokens before deleting it, unless
 * `--keep-tokens` is passed.
 */
class OAuthClientDeleteCommand extends Command
{
    private const ARG_ID = 'id';
    private const OPT_KEEP_TOKENS = 'keep-tokens';

    /**
     * @param ClientRepository $clientRepository
     */
    public function __construct(
        private readonly ClientRepository $clientRepository
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:oauth:client:delete')
            ->setDescription(
                'Delete an OAuth client by id, revoking the access tokens it issued.'
            )
            ->addArgument(self::ARG_ID, InputArgument::REQUIRED, 'OAuth client id (numeric).')
            ->addOption(
                self::OPT_KEEP_TOKENS,
                null,
                InputOption::VALUE_NONE,
                'Leave already-issued access tokens valid until they expire.'
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
        $id = $this->clientId($input);
        $keepTokens = (bool) $input->getOption(self::OPT_KEEP_TOKENS);

        try {
            $client = $this->clientRepository->getById($id);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('OAuth client %d not found.', $id));
        }

        $name = $client->getName();

        try {
            $revoked = $this->clientRepository->deleteById($id, $keepTokens);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'Failed to delete OAuth client %d: %s',
                $id,
                $e->getMessage()
            ), 0, $e);
        }

        if (!$keepTokens) {
            $output->writeln(sprintf('<comment>Access tokens revoked: %d.</comment>', $revoked));
        }
        $output->writeln(sprintf('<info>OAuth client %d ("%s") deleted.</info>', $id, $name));
        if ($keepTokens) {
            $output->writeln(
                '<comment>Tokens issued by this client are still valid — revoke them with'
                . ' magebit:mcp:token:revoke if that was not intended.</comment>'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return int
     * @throws RuntimeException
     */
    private function clientId(InputInterface $input): int
    {
        $raw = $input->getArgument(self::ARG_ID);
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw === 0) {
            throw new RuntimeException('OAuth client id must be a positive integer.');
        }
        return (int) $raw;
    }
}
