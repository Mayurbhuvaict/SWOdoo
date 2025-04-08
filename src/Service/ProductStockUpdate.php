<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class ProductStockUpdate
{
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function getUpdateProductStockData($request, $context): array
    {
        $responseProductData = [];
        $productStockDataArray = $request->get('stock_info');
        foreach ($productStockDataArray as $productStockData) {
            $productData = $this->updateProductStockData($productStockData, $context);
            if ($productData) {
                $stock = intval($productData->getStock() + $productStockData['new_stock']);
                $data = [
                    'id' => $productData->getId(),
                    'stock' => $stock,
                ];
                $dataUpdate = $this->productRepository->update([$data], $context);
                if (! $dataUpdate->getErrors()) {
                    $responseProductData[] = [
                        'odooId' => $productStockData['id'],
                        'shopwareId' => $productStockData['shopware_product_id'],
                    ];
                }
            } else {
                $responseData = [
                    'type' => 'Error',
                    'statusCode' => 400,
                    'message' => 'Product is not found.'
                ];
            }
        }

        if ($responseProductData) {
            $responseData = [
                'type' => 'Success',
                'statusCode' => 200,
                'productData' => $responseProductData
            ];
        }
        return $responseData;
    }

    public function updateProductStockData($productStockData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $productStockData['id']));
        $criteria->addFilter(new EqualsFilter('id', $productStockData['shopware_product_id']));
        return $this->productRepository->search($criteria, $context)->first();
    }
}
