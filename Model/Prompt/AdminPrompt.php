<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Prompt;

use Magebit\Mcp\Model\ResourceModel\Prompt\AdminPrompt as AdminPromptResource;
use Magebit\Mcp\Model\Trait\ReturnsStrictTypedData;
use Magento\Framework\Model\AbstractModel;

class AdminPrompt extends AbstractModel
{
    use ReturnsStrictTypedData;

    public const ID = 'id';
    public const NAME = 'name';
    public const TITLE = 'title';
    public const DESCRIPTION = 'description';
    public const BODY = 'body';
    public const ARGUMENTS_JSON = 'arguments_json';
    public const REQUIRES_WRITE = 'requires_write';
    public const IS_ACTIVE = 'is_active';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const NAME_PREFIX = 'custom.';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AdminPromptResource::class);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->getDataIntOrNull(self::ID, cast: true);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->getDataString(self::NAME);
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
        return $this;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getDataString(self::TITLE);
    }

    /**
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->setData(self::TITLE, $title);
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        $value = $this->getDataStringOrNull(self::DESCRIPTION);
        return $value ?? '';
    }

    /**
     * @param string $description
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->setData(self::DESCRIPTION, $description === '' ? null : $description);
        return $this;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->getDataString(self::BODY);
    }

    /**
     * @param string $body
     * @return self
     */
    public function setBody(string $body): self
    {
        $this->setData(self::BODY, $body);
        return $this;
    }

    /**
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    public function getArguments(): array
    {
        $raw = $this->getDataStringOrNull(self::ARGUMENTS_JSON);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '';
            if ($name === '') {
                continue;
            }
            $description = isset($entry['description']) && is_string($entry['description'])
                ? $entry['description']
                : '';
            $required = isset($entry['required']) && (bool) $entry['required'];
            $out[] = [
                'name' => $name,
                'description' => $description,
                'required' => $required,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{name: string, description?: string, required?: bool}> $arguments
     * @return self
     */
    public function setArguments(array $arguments): self
    {
        $normalised = [];
        foreach ($arguments as $argument) {
            $name = $argument['name'];
            if ($name === '') {
                continue;
            }
            $normalised[] = [
                'name' => $name,
                'description' => $argument['description'] ?? '',
                'required' => !empty($argument['required']),
            ];
        }
        if ($normalised === []) {
            $this->setData(self::ARGUMENTS_JSON, null);
            return $this;
        }
        $this->setData(self::ARGUMENTS_JSON, json_encode($normalised, JSON_THROW_ON_ERROR));
        return $this;
    }

    /**
     * @return bool
     */
    public function getRequiresWrite(): bool
    {
        $raw = $this->getData(self::REQUIRES_WRITE);
        return is_scalar($raw) && (int) $raw === 1;
    }

    /**
     * @param bool $requiresWrite
     * @return self
     */
    public function setRequiresWrite(bool $requiresWrite): self
    {
        $this->setData(self::REQUIRES_WRITE, $requiresWrite ? 1 : 0);
        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        $raw = $this->getData(self::IS_ACTIVE);
        return is_scalar($raw) && (int) $raw === 1;
    }

    /**
     * @param bool $active
     * @return self
     */
    public function setIsActive(bool $active): self
    {
        $this->setData(self::IS_ACTIVE, $active ? 1 : 0);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getDataStringOrNull(self::CREATED_AT);
    }

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getDataStringOrNull(self::UPDATED_AT);
    }
}
