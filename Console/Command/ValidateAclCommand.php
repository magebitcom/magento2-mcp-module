<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Console\Command;

use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Contract\ToolContractChecker;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento magebit:mcp:tools:validate` (alias `…:validate-acl`) — CI gate that catches tools whose
 * `getAclResource()` is missing from the merged acl.xml tree, dotted tool names in descriptions, and
 * bulk tools that break the bulk contract. Underlying resources of plain tools are reported only as
 * a warning — they belong to other modules.
 */
class ValidateAclCommand extends Command
{
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ProviderInterface $aclResourceProvider,
        private readonly ToolContractChecker $contractChecker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magebit:mcp:tools:validate')
            ->setAliases(['magebit:mcp:tools:validate-acl'])
            ->setDescription(
                'Fail if a registered MCP tool has an undeclared ACL resource, a dotted tool name in its '
                . 'description, or breaks the bulk tool contract.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $declared = $this->flattenResourceIds($this->aclResourceProvider->getAclResources());
        $missing = [];
        $missingUnderlying = [];
        $contractViolations = [];

        $tools = $this->toolRegistry->all();
        $knownNames = array_values(array_map(
            static fn ($tool): string => $tool->getName(),
            $tools
        ));

        foreach ($tools as $tool) {
            foreach ($this->contractChecker->dottedNameViolations(
                $tool->getDescription(),
                $knownNames,
                ToolContractChecker::DOMAIN_PREFIXES
            ) as $violation) {
                $contractViolations[] = sprintf('%s: description: %s', $tool->getName(), $violation);
            }

            $bulk = $tool instanceof BulkToolAdapter ? $tool->getBulkTool() : $tool;
            if ($bulk instanceof BulkToolInterface) {
                foreach ($this->contractChecker->bulkViolations($bulk, $declared, null) as $violation) {
                    $contractViolations[] = $violation;
                }
            }

            $resource = $tool->getAclResource();
            if (!in_array($resource, $declared, true)) {
                $missing[] = sprintf('%s → %s', $tool->getName(), $resource);
            }

            $underlying = $tool instanceof UnderlyingAclAwareInterface
                ? $tool->getUnderlyingAclResource()
                : null;
            if ($underlying !== null && !in_array($underlying, $declared, true)) {
                $missingUnderlying[] = sprintf('%s → %s', $tool->getName(), $underlying);
            }
        }

        $failed = false;
        if ($missing !== []) {
            $failed = true;
            $output->writeln('<error>MCP tools referencing undeclared ACL resources:</error>');
            foreach ($missing as $row) {
                $output->writeln(' - ' . $row);
            }
        }

        if ($contractViolations !== []) {
            $failed = true;
            $output->writeln('<error>MCP tool contract violations:</error>');
            foreach ($contractViolations as $row) {
                $output->writeln(' - ' . $row);
            }
        }

        if ($failed) {
            return Command::FAILURE;
        }

        if ($missingUnderlying !== []) {
            // Warning, not a failure: the resource belongs to another module, which
            // may legitimately be absent on this install.
            $output->writeln('<comment>MCP tools whose underlying ACL resource does not resolve here:</comment>');
            foreach ($missingUnderlying as $row) {
                $output->writeln(' - ' . $row);
            }
            $output->writeln('<comment>These tools stay hidden from tools/list for every role.</comment>');
        }

        $output->writeln('<info>OK — every registered MCP tool has its ACL resource declared and meets the contract.</info>');
        return Command::SUCCESS;
    }

    /**
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
