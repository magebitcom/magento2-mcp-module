<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Api\ToolRegistryInterface;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:tools:validate-acl`
 *
 * Walks every registered MCP tool and confirms its getAclResource() is declared
 * somewhere in the merged acl.xml tree. Catches "tool registered but ACL entry
 * forgotten" drift at build/CI time instead of on first call.
 */
class ValidateAclCommand extends Command
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ProviderInterface $aclResourceProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magebit:mcp:tools:validate-acl')
            ->setDescription('Fail if any registered MCP tool references an ACL resource not declared in acl.xml.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $declared = $this->flattenResourceIds($this->aclResourceProvider->getAclResources());
        $missing = [];

        foreach ($this->toolRegistry->all() as $tool) {
            $resource = $tool->getAclResource();
            if (!in_array($resource, $declared, true)) {
                $missing[] = sprintf('%s → %s', $tool->getName(), $resource);
            }
        }

        if ($missing !== []) {
            $output->writeln('<error>MCP tools referencing undeclared ACL resources:</error>');
            foreach ($missing as $row) {
                $output->writeln(' - ' . $row);
            }
            return Command::FAILURE;
        }

        $output->writeln('<info>OK — every registered MCP tool has its ACL resource declared.</info>');
        return Command::SUCCESS;
    }

    /**
     * Recursively collect every `id` key from the nested ACL resource tree.
     *
     * @param array<int, array<string, mixed>> $resources
     * @return array<int, string>
     */
    private function flattenResourceIds(array $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            if (isset($resource['id']) && is_string($resource['id'])) {
                $ids[] = $resource['id'];
            }
            if (isset($resource['children']) && is_array($resource['children'])) {
                /** @var array<int, array<string, mixed>> $children */
                $children = $resource['children'];
                foreach ($this->flattenResourceIds($children) as $childId) {
                    $ids[] = $childId;
                }
            }
        }
        return $ids;
    }
}
