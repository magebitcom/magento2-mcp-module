<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config\Backend;

use Magebit\Mcp\Model\Config\ConfigWriteConfig;
use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Backend model for `magebit_mcp/config_write/allowed_paths`. An entry the policy could never match
 * is an allowlist that silently does nothing, so it is refused at save time instead.
 */
class AllowedPaths extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ConfigWriteConfig $writeConfig
     * @param ConfigPathFormat $pathFormat
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ConfigWriteConfig $writeConfig,
        private readonly ConfigPathFormat $pathFormat,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $raw = $this->getValue();
        if ($raw === null || $raw === '') {
            return parent::beforeSave();
        }

        if (!is_string($raw)) {
            throw new LocalizedException(__('Allowed Paths must be plain text, one path per line.'));
        }

        $invalid = [];
        foreach ($this->writeConfig->parseAllowedPaths($raw) as $path) {
            if (!$this->pathFormat->isCanonical($path)) {
                $invalid[] = $this->pathFormat->forMessage($path);
            }
        }

        if ($invalid !== []) {
            throw new LocalizedException(
                __(
                    'Allowed Paths contains %1 entry (entries) the configuration writer can never '
                    . 'match: %2. Each line must be two or more slash-separated segments of letters, '
                    . 'digits and underscores, for example tax/calculation/based_on. Wildcards are '
                    . 'not supported. Prefix a line with # to comment it out.',
                    count($invalid),
                    implode(', ', $invalid)
                )
            );
        }

        return parent::beforeSave();
    }
}
