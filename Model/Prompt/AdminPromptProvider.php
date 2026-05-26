<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Api\PromptInterface;

/**
 * Runtime loader for admin-managed prompts. Reads active rows on demand and
 * wraps each in an {@see AdminPromptAdapter}. Injected into {@see PromptRegistry}
 * so the registry can layer admin rows on top of DI-registered prompts without
 * the registry itself needing to know about the DB.
 */
class AdminPromptProvider
{
    /**
     * @param AdminPromptRepository $repository
     * @param AdminPromptAdapterFactory $adapterFactory
     */
    public function __construct(
        private readonly AdminPromptRepository $repository,
        private readonly AdminPromptAdapterFactory $adapterFactory
    ) {
    }

    /**
     * Active admin prompts keyed by canonical name. Empty when nothing is
     * registered or persistence is unavailable — callers MUST tolerate that.
     *
     * @return array<string, PromptInterface>
     */
    public function getAll(): array
    {
        $out = [];
        foreach ($this->repository->getActive() as $model) {
            $name = $model->getName();
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $out[$name] = $this->adapterFactory->create(['model' => $model]);
        }
        return $out;
    }
}
