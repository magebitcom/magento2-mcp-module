<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `bin/magento magebit:mcp:oauth:client:set-status <id> --disabled|--enabled`.
 *
 * Disabling revokes the client's live access tokens too — on its own the flag
 * only blocks new grants, so old tokens would keep authenticating at /mcp.
 */
class OAuthClientSetStatusCommand extends Command
{
    private const ARG_ID = 'id';
    private const OPT_DISABLED = 'disabled';
    private const OPT_ENABLED = 'enabled';

    /**
     * @param ClientRepository $clientRepository
     * @param TokenRepository $tokenRepository
     */
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:oauth:client:set-status')
            ->setDescription('Enable or disable an OAuth client. Disabling revokes its live access tokens.')
            ->addArgument(self::ARG_ID, InputArgument::REQUIRED, 'OAuth client id (numeric).')
            ->addOption(
                self::OPT_DISABLED,
                null,
                InputOption::VALUE_NONE,
                'Switch the client off — it can no longer mint or refresh tokens.'
            )
            ->addOption(
                self::OPT_ENABLED,
                null,
                InputOption::VALUE_NONE,
                'Switch the client back on.'
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
        $disable = (bool) $input->getOption(self::OPT_DISABLED);
        $enable = (bool) $input->getOption(self::OPT_ENABLED);
        if ($disable === $enable) {
            throw new RuntimeException('Pass exactly one of --disabled or --enabled.');
        }

        $raw = $input->getArgument(self::ARG_ID);
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw === 0) {
            throw new RuntimeException('OAuth client id must be a positive integer.');
        }
        $id = (int) $raw;

        try {
            $client = $this->clientRepository->getById($id);
        } catch (NoSuchEntityException) {
            throw new RuntimeException(sprintf('OAuth client %d not found.', $id));
        }

        if ($client->isDisabled() === $disable) {
            $output->writeln(sprintf(
                '<comment>OAuth client %d ("%s") is already %s — nothing to do.</comment>',
                $id,
                $client->getName(),
                $disable ? 'disabled' : 'enabled'
            ));
            return Command::SUCCESS;
        }

        try {
            $client->setDisabled($disable);
            $this->clientRepository->save($client);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'Failed to update OAuth client %d: %s',
                $id,
                $e->getMessage()
            ), 0, $e);
        }

        $output->writeln(sprintf(
            '<info>OAuth client %d ("%s") %s.</info>',
            $id,
            $client->getName(),
            $disable ? 'disabled' : 'enabled'
        ));

        if (!$disable) {
            return Command::SUCCESS;
        }

        try {
            $revoked = $this->tokenRepository->revokeAllForClient($id);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '<error>Client disabled, but revoking its live tokens failed: %s.'
                . ' Those tokens still authenticate — revoke them with magebit:mcp:token:revoke.</error>',
                $e->getMessage()
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<comment>Access tokens revoked: %d.</comment>', $revoked));

        return Command::SUCCESS;
    }
}
