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
 * Form data provider for `magebit_mcp_token_form`.
 *
 * MCP tokens are mint-once, rotate-not-edit: the plaintext is shown only at
 * creation and the hash is immutable afterwards. This provider therefore
 * always returns an empty row — there is no edit flow to hydrate.
 *
 * Even so, we hand the base class a real token collection so {@see
 * AbstractDataProvider::addFilter()} and its siblings have something
 * non-null to call. The collection is kept empty at construction; {@see
 * self::getData()} ignores it entirely.
 */
class TokenDataProvider extends AbstractDataProvider
{
    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param RequestInterface $request
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
     * Hand the UI component a one-row dataset — MCP tokens are mint-once.
     *
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
