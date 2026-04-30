<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\OAuth;

use InvalidArgumentException;
use Magebit\Mcp\Api\Data\OAuth\ClientPresetInterface;

/**
 * Immutable {@see ClientPresetInterface} value object. Constructor validates
 * that `id` is a usable JS key and that redirect URIs are strings — the
 * provider's uniqueness check picks up duplicate ids on the way in.
 */
class ClientPreset implements ClientPresetInterface
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var array<int, string> */
    private readonly array $redirectUris;

    /**
     * @param string $id
     * @param string $label
     * @param string $name
     * @param array<int, string> $redirectUris
     * @return void
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $name,
        array $redirectUris
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Preset id "%s" must match %s.',
                $id,
                self::ID_PATTERN
            ));
        }

        $clean = [];
        foreach ($redirectUris as $uri) {
            if (is_string($uri) && trim($uri) !== '') {
                $clean[] = trim($uri);
            }
        }
        $this->redirectUris = array_values(array_unique($clean));
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<int, string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }
}
