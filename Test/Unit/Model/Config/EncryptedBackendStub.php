<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Config;

use Magento\Config\Model\Config\Backend\Encrypted;

/**
 * Stands in for a module-supplied backend model extending Magento's Encrypted.
 * A real class is required because the guard resolves it by name.
 */
class EncryptedBackendStub extends Encrypted
{
}
