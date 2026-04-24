<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Ui\DataProvider\Form;

use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Form data provider for `magebit_mcp_token_form`. Tokens are mint-once
 * (rotate-not-edit) so this always returns an empty row. A real collection
 * is still passed to the base class so {@see AbstractDataProvider::addFilter()}
 * and siblings have something non-null to call.
 */
class TokenDataProvider extends AbstractDataProvider
{
    /**
     * @param array $meta
     * @param array $data
     * @phpstan-param array<string, mixed> $meta
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        $id = $this->request->getParam($this->getRequestFieldName());
        $key = is_scalar($id) && (int) $id > 0 ? (int) $id : 0;

        return [
            $key => [
                'id' => $key,
                'allow_writes' => 1,
            ],
        ];
    }
}
