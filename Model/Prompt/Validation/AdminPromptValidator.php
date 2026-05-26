<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt\Validation;

use InvalidArgumentException;
use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\Prompt\PromptRegistry;
use Magebit\Mcp\Model\Util\RegisteredEntries;

class AdminPromptValidator
{
    private const ARGUMENT_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i';

    public const MAX_BODY_BYTES = 8192;
    public const MAX_TITLE_BYTES = 255;
    public const MAX_DESCRIPTION_BYTES = 512;
    public const MAX_ARGUMENT_NAME_BYTES = 64;
    public const MAX_NAME_BYTES = 96;

    /**
     * @param PromptRegistry $promptRegistry
     */
    public function __construct(
        private readonly PromptRegistry $promptRegistry
    ) {
    }

    /**
     * @param AdminPrompt $prompt
     * @return void
     * @throws InvalidArgumentException
     */
    public function validate(AdminPrompt $prompt): void
    {
        $this->validateName($prompt);
        $this->validateTitle($prompt);
        $this->validateDescription($prompt);
        $arguments = $this->validateArguments($prompt);
        $this->validateBody($prompt, $arguments);
    }

    /**
     * @param AdminPrompt $prompt
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateName(AdminPrompt $prompt): void
    {
        $name = $prompt->getName();
        if ($name === '') {
            throw new InvalidArgumentException(
                'Pick a prompt name — the slug after "custom." that the assistant uses to find this prompt.'
            );
        }
        if (strlen($name) > self::MAX_NAME_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Prompt name is too long (max %d characters).',
                self::MAX_NAME_BYTES
            ));
        }
        if (!str_starts_with($name, AdminPrompt::NAME_PREFIX)) {
            throw new InvalidArgumentException(
                'Prompt names must start with "custom." so they cannot collide with built-in prompts.'
            );
        }
        $suffix = substr($name, strlen(AdminPrompt::NAME_PREFIX));
        if ($suffix === '') {
            throw new InvalidArgumentException(
                'Add a slug after "custom." — e.g. custom.greet_customer.'
            );
        }
        if (preg_match(RegisteredEntries::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                'Prompt name may only contain lowercase letters, numbers, and underscores, with at'
                . ' least one dot — e.g. custom.greet or custom.email.welcome.'
            );
        }
        if (in_array($name, $this->promptRegistry->getStaticNames(), true)) {
            throw new InvalidArgumentException(sprintf(
                'A built-in prompt named "%s" already exists. Pick a different slug.',
                $name
            ));
        }
    }

    /**
     * @param AdminPrompt $prompt
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateTitle(AdminPrompt $prompt): void
    {
        $title = trim($prompt->getTitle());
        if ($title === '') {
            throw new InvalidArgumentException(
                'Give the prompt a short title — this is what users see in the MCP client menu.'
            );
        }
        if (strlen($title) > self::MAX_TITLE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Title is too long (max %d characters).',
                self::MAX_TITLE_BYTES
            ));
        }
    }

    /**
     * @param AdminPrompt $prompt
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateDescription(AdminPrompt $prompt): void
    {
        $description = $prompt->getDescription();
        if (strlen($description) > self::MAX_DESCRIPTION_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Description is too long (max %d characters).',
                self::MAX_DESCRIPTION_BYTES
            ));
        }
    }

    /**
     * @param AdminPrompt $prompt
     * @return array<int, array{name: string, description: string, required: bool}>
     * @throws InvalidArgumentException
     */
    private function validateArguments(AdminPrompt $prompt): array
    {
        $arguments = $prompt->getArguments();
        $seen = [];
        foreach ($arguments as $argument) {
            $name = $argument['name'];
            if (strlen($name) > self::MAX_ARGUMENT_NAME_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'Argument name "%s" is too long (max %d characters).',
                    $name,
                    self::MAX_ARGUMENT_NAME_BYTES
                ));
            }
            if (preg_match(self::ARGUMENT_NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Argument name "%s" may only contain lowercase letters, numbers, and underscores'
                    . ' and must start with a letter.',
                    $name
                ));
            }
            if (isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Argument "%s" is declared twice — argument names must be unique.',
                    $name
                ));
            }
            $seen[$name] = true;
        }
        return $arguments;
    }

    /**
     * @param AdminPrompt $prompt
     * @param array<int, array{name: string, description: string, required: bool}> $arguments
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateBody(AdminPrompt $prompt, array $arguments): void
    {
        $body = $prompt->getBody();
        if (trim($body) === '') {
            throw new InvalidArgumentException(
                'Write the prompt body — this is what the assistant will read.'
            );
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Prompt body is too long (max %d bytes).',
                self::MAX_BODY_BYTES
            ));
        }

        $declared = [];
        foreach ($arguments as $argument) {
            $declared[$argument['name']] = false;
        }

        if (preg_match_all(self::PLACEHOLDER_PATTERN, $body, $matches) !== false) {
            foreach ($matches[1] as $token) {
                $token = strtolower($token);
                if (!array_key_exists($token, $declared)) {
                    throw new InvalidArgumentException(sprintf(
                        'The body references {{%s}} but no argument named "%s" is declared. Add the'
                        . ' argument or remove the placeholder.',
                        $token,
                        $token
                    ));
                }
                $declared[$token] = true;
            }
        }

        foreach ($declared as $name => $used) {
            if (!$used) {
                throw new InvalidArgumentException(sprintf(
                    'Argument "%s" is declared but never used in the body — add {{%s}} somewhere'
                    . ' or remove the argument.',
                    $name,
                    $name
                ));
            }
        }
    }
}
