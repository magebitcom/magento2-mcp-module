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
use Magebit\Mcp\Api\OAuth\ClientPresetProviderInterface;

/**
 * DI-array-backed registry of OAuth client form presets. The "custom" entry
 * is hard-wired as the first option (empty Name, empty redirect URIs); other
 * presets come from etc/di.xml.
 */
class ClientPresetProvider implements ClientPresetProviderInterface
{
    private const CUSTOM_ID = 'custom';

    /** @var array<int, ClientPresetInterface> */
    private array $presets;

    /**
     * @param array<int|string, ClientPresetInterface> $presets
     * @throws InvalidArgumentException When an id is reused or not a ClientPresetInterface.
     */
    public function __construct(array $presets = [])
    {
        $custom = new ClientPreset(
            id: self::CUSTOM_ID,
            label: (string) __('Custom — fill in manually'),
            name: '',
            redirectUris: []
        );

        $seen = [self::CUSTOM_ID => true];
        $ordered = [$custom];
        foreach ($presets as $preset) {
            if (!$preset instanceof ClientPresetInterface) {
                throw new InvalidArgumentException(
                    'ClientPresetProvider only accepts ClientPresetInterface entries.'
                );
            }
            $id = $preset->getId();
            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate ClientPreset id "%s".',
                    $id
                ));
            }
            $seen[$id] = true;
            $ordered[] = $preset;
        }

        $this->presets = $ordered;
    }

    /**
     * @return array<int, ClientPresetInterface>
     */
    public function getAll(): array
    {
        return $this->presets;
    }
}
