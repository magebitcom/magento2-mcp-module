<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Api\OAuth;

use Magebit\Mcp\Api\Data\OAuth\ClientPresetInterface;

/**
 * Supplies the OAuth client edit form with its "Preset" dropdown contents.
 * Implementations are DI-array backed so satellite modules can append
 * additional presets without touching core.
 */
interface ClientPresetProviderInterface
{
    /**
     * @return array<int, ClientPresetInterface>
     */
    public function getAll(): array;
}
