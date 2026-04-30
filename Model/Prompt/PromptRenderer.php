<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

class PromptRenderer
{
    /**
     * @param PromptInterface $prompt
     * @param array<string, string> $arguments
     * @return array<int, PromptMessage>
     */
    public function render(PromptInterface $prompt, array $arguments): array
    {
        $declared = $prompt->getArguments();
        $search = [];
        $replace = [];
        foreach ($declared as $argument) {
            $search[] = '{{' . $argument->name . '}}';
            $replace[] = $arguments[$argument->name] ?? '';
        }

        $rendered = [];
        foreach ($prompt->getMessages() as $message) {
            $rendered[] = $search === []
                ? $message
                : new PromptMessage($message->role, str_replace($search, $replace, $message->text));
        }
        return $rendered;
    }
}
