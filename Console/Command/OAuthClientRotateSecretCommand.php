<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Model\OAuth\ClientCredentialIssuer;
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
 * `bin/magento magebit:mcp:oauth:client:rotate-secret <id>` — mint a new client
 * secret, keeping the client id. Already-issued access tokens keep working
 * unless `--revoke-tokens` is passed.
 */
class OAuthClientRotateSecretCommand extends Command
{
    private const ARG_ID = 'id';
    private const OPT_REVOKE_TOKENS = 'revoke-tokens';

    /**
     * @param ClientRepository $clientRepository
     * @param ClientCredentialIssuer $issuer
     * @param TokenRepository $tokenRepository
     */
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ClientCredentialIssuer $issuer,
        private readonly TokenRepository $tokenRepository
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:oauth:client:rotate-secret')
            ->setDescription('Issue a new secret for an OAuth client. The new secret is shown once.')
            ->addArgument(self::ARG_ID, InputArgument::REQUIRED, 'OAuth client id (numeric).')
            ->addOption(
                self::OPT_REVOKE_TOKENS,
                null,
                InputOption::VALUE_NONE,
                'Also revoke the access tokens this client already issued.'
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

        try {
            $plaintext = $this->issuer->rotateSecret($client);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'Failed to rotate the secret for OAuth client %d: %s',
                $id,
                $e->getMessage()
            ), 0, $e);
        }

        if ((bool) $input->getOption(self::OPT_REVOKE_TOKENS)) {
            try {
                $revoked = $this->tokenRepository->revokeAllForClient($id);
                $output->writeln(sprintf('<comment>Access tokens revoked: %d.</comment>', $revoked));
            } catch (Throwable $e) {
                // Rotation is already committed, so report and carry on — the new
                // secret must still reach the operator.
                $output->writeln(sprintf(
                    '<error>Secret rotated, but revoking tokens failed: %s.'
                    . ' Revoke them with magebit:mcp:token:revoke.</error>',
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            '<info>Secret rotated for OAuth client %d ("%s").</info>',
            $id,
            $client->getName()
        ));
        $output->writeln(sprintf('<comment>Client ID (unchanged):</comment> %s', $client->getClientId()));
        $output->writeln('');
        $output->writeln('<comment>New client secret — store it now, it will not be shown again:</comment>');
        $output->writeln($plaintext);

        return Command::SUCCESS;
    }
}
