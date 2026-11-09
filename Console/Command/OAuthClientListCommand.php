<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Api\Data\OAuth\ClientInterface;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\OAuth\AuthMode;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:oauth:client:list` — dump every registered OAuth client.
 *
 * Client secrets are stored hashed and never appear here.
 */
class OAuthClientListCommand extends Command
{
    /**
     * @param ClientRepository $clientRepository
     * @param AdminUserLookup $adminUserLookup
     */
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly AdminUserLookup $adminUserLookup
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('magebit:mcp:oauth:client:list')
            ->setDescription('List the registered MCP OAuth clients.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clients = $this->clientRepository->getList();
        if ($clients === []) {
            $output->writeln('<comment>No OAuth clients registered.</comment>');
            return Command::SUCCESS;
        }

        $usernames = $this->usernamesFor($clients);

        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'Name',
            'Client ID',
            'Mode',
            'Bound admin',
            'Tools',
            'Redirect URIs',
            'Status',
            'Created',
        ]);
        foreach ($clients as $client) {
            $table->addRow([
                $client->getId() ?? 0,
                $client->getName(),
                $client->getClientId(),
                $client->getAuthMode()->value,
                $this->boundAdminOf($client, $usernames),
                $this->toolsOf($client),
                implode("\n", $client->getRedirectUris()),
                $client->isDisabled() ? 'disabled' : 'enabled',
                $client->getCreatedAt() ?? '-',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * @param Client $client
     * @return string
     */
    private function toolsOf(Client $client): string
    {
        $tools = $client->getAllowedTools();
        if ($tools === [ClientInterface::ALLOW_ALL_TOOLS_SENTINEL]) {
            return 'all registered';
        }
        return implode("\n", $tools);
    }

    /**
     * @param Client $client
     * @param array<int, string> $usernames
     * @return string
     */
    private function boundAdminOf(Client $client, array $usernames): string
    {
        if ($client->getAuthMode() === AuthMode::SHARED) {
            $id = $client->getServiceAdminUserId();
            if ($id === null) {
                return '(unset — cannot issue tokens)';
            }
            return $usernames[$id] ?? ('#' . $id);
        }

        $restrictions = [];
        foreach ($client->getAllowedAdminUserIds() as $id) {
            $restrictions[] = $usernames[$id] ?? ('#' . $id);
        }
        $roleCount = count($client->getAllowedAdminRoleIds());
        if ($roleCount > 0) {
            $restrictions[] = sprintf('+%d role(s)', $roleCount);
        }

        return $restrictions === [] ? 'any admin' : implode(', ', $restrictions);
    }

    /**
     * @param array<int, Client> $clients
     * @return array<int, string>
     */
    private function usernamesFor(array $clients): array
    {
        $ids = [];
        foreach ($clients as $client) {
            $serviceAdmin = $client->getServiceAdminUserId();
            if ($serviceAdmin !== null) {
                $ids[] = $serviceAdmin;
            }
            foreach ($client->getAllowedAdminUserIds() as $id) {
                $ids[] = $id;
            }
        }

        $map = [];
        foreach ($this->adminUserLookup->listByIds($ids) as $id => $user) {
            $username = (string) $user->getUsername();
            $map[$id] = $username !== '' ? $username : ('#' . $id);
        }
        return $map;
    }
}
