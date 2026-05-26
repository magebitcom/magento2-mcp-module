<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

/**
 * Wraps an {@see AdminPrompt} DB row so it satisfies the
 * {@see PromptInterface} contract used by `prompts/list` / `prompts/get`.
 *
 * The body is exposed as a single `user`-role message — multi-role prompts
 * would need an extension to the storage schema; v1 keeps the editor a single
 * textarea.
 */
class AdminPromptAdapter implements PromptInterface
{
    /**
     * @param AdminPrompt $model
     */
    public function __construct(
        private readonly AdminPrompt $model
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->model->getName();
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->model->getTitle();
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->model->getDescription();
    }

    /**
     * @return array<int, PromptArgument>
     */
    public function getArguments(): array
    {
        $arguments = [];
        foreach ($this->model->getArguments() as $argument) {
            $arguments[] = new PromptArgument(
                $argument['name'],
                $argument['description'],
                $argument['required']
            );
        }
        return $arguments;
    }

    /**
     * @return bool
     */
    public function getRequiresWrite(): bool
    {
        return $this->model->getRequiresWrite();
    }

    /**
     * @return array<int, PromptMessage>
     */
    public function getMessages(): array
    {
        return [new PromptMessage(PromptMessage::ROLE_USER, $this->model->getBody())];
    }
}
