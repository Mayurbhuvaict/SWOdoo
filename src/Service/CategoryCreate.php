<?php

namespace ICTECHOdooShopwareConnector\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class CategoryCreate
{
    public const END_POINT = '/shop/product_category';

    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    /**
     * @throws GuzzleException
     */
    public function categoryInsert($catId, $context): array
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $responseData = $responseDataArray = [];
        if ($catId === 'getAllCatData') {
            $apiUrl = $odooUrl . self::END_POINT;
        } else {
            $apiUrl = $odooUrl . self::END_POINT . '/' . $catId;
        }
        $odooCategoryAPIData = $this->getCategoryAPIData($apiUrl);
        if ($odooCategoryAPIData && $odooCategoryAPIData->result->success) {
            foreach ($odooCategoryAPIData->result->data as $odooCategoryData) {
                $oddCategoryId = $odooCategoryData->id;
                $oddCategoryName = $odooCategoryData->name;
                $defaultCategory = $this->getDefaultCategoryData($context);
//                dd($odooCategoryData, $defaultCategory);
//                dd($odooCategoryData->parent_id === false , $oddCategoryId !== 1 , $defaultCategory);
//                if ($odooCategoryData->parent_id === false && $oddCategoryId !== 1 && $defaultCategory) {
                if ($odooCategoryData->parent_id === false && $defaultCategory) {
                    $oddCategoryParentId = $defaultCategory->getId();
                } else {
                    $oddCategoryParentId = $odooCategoryData->parent_id;
                }
                $shopwareCategoryData = $this->getCategoryData($oddCategoryId, $context);
                $responseDataArray[] = $this->insertCategoryData($oddCategoryId, $oddCategoryParentId, $shopwareCategoryData, $oddCategoryName, $context);
            }
            if ($responseDataArray) {
                $responseData = [
                    'categoryData' => $responseDataArray
                ];
            }
        }
        if ($catId === 'getAllCatData') {
            $this->client->post(
                $apiUrl,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $responseData
                ]
            );
        }
        return $responseDataArray;
    }

    public function getCategoryAPIData($apiUrl)
    {
        $apiResponse = $this->client->get(
            $apiUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
            ]
        );
        return json_decode($apiResponse->getBody()->getContents());
    }

    public function getDefaultCategoryData($context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', 0));
        return $this->categoryRepository->search($criteria, $context)->first();
    }

    public function getCategoryData($oddCategoryId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', $oddCategoryId));
        return $this->categoryRepository->search($criteria, $context)->first();
    }

    public function insertCategoryData($oddCategoryId, $oddCategoryParentId, $shopwareCategoryData, $oddCategoryName, $context): array
    {
//        if ($oddCategoryParentId) {
        $categoryParentId = $this->findUpdateCategoryData($oddCategoryParentId, $context);
        $categoryData = [
            'id' => $shopwareCategoryData ? $shopwareCategoryData->getId() : Uuid::randomHex(),
            'name' => $oddCategoryName,
            'parentId' => $categoryParentId ? $categoryParentId->getId() : $oddCategoryParentId,
            'active' => true,
            'customFields' => ['odoo_category_id' => $oddCategoryId],
        ];
        if (isset ($categoryParentId)) {
            $categoryData['parentId'] = $categoryParentId->getId();
            $responseDataArray['shopwareParentId'] = $categoryParentId->getId();
        }
//            dd($categoryData);
        $this->categoryRepository->upsert([$categoryData], $context);
        $responseDataArray = [
            'odooId' => $oddCategoryId,
            'shopwareId' => $categoryData['id'],
//                'shopwareParentId' => $categoryParentId->getId(),

        ];
        /*} else {
            $categoryData = [
                'id' => $shopwareCategoryData ? $shopwareCategoryData->getId() : Uuid::randomHex(),
                'name' => $oddCategoryName,
                'active' => true,
                'customFields' => ['odoo_category_id' => $oddCategoryId],
            ];
//            dd($categoryData);
            $this->categoryRepository->upsert([$categoryData], $context);
            $responseDataArray = [
                'odooId' => $oddCategoryId,
                'shopwareId' => $categoryData['id'],
            ];
        }*/
        return $responseDataArray;
    }

    public function createCategoryData($catDataArray, $context): array
    {
        $categoryData = [];
        foreach ($catDataArray as $catData) {
            $oddCategoryId = $catData['id'];
            $oddCategoryName = $catData['name'];
            if (array_key_exists('parent_id', $catData)) {
                $oddCategoryParentId = $catData['parent_id'];
            } else {
                $oddCategoryParentId = null;
            }
            $shopwareCategoryData = $this->getCategoryData($oddCategoryId, $context);
            $categoryData[] = $this->insertCategoryData($oddCategoryId, $oddCategoryParentId, $shopwareCategoryData, $oddCategoryName, $context);
        }
        return $categoryData;
    }

    public function updateCategory($updateCategoryData, $context): array
    {
        $responseData = $responseDataArray = [];
        foreach ($updateCategoryData as $updateData) {
            if (array_key_exists('id', $updateData)) {
                $odooId = $updateData['id'];
            }
            $updateCatData = $this->findUpdateCategoryData($odooId, $context);

            if (array_key_exists('name', $updateData)) {
                $updateCategoryName = $updateData['name'];
            } else {
                $updateCategoryName = $updateCatData->getName();
            }

            if (array_key_exists('parent_data', $updateData)) {
                $parentId = $updateData['parent_data']['parent_id'];
            }

            if (! $updateCatData && $updateData->parent_data) {
                $data = [
                    'id' => $updateCatData->getId(),
                    'name' => $updateCategoryName,
                    'active' => true,
                    'customFields' => ['odoo_category_id' => $odooId],
                ];
                if (isset($parentId)) {
                    $data['parentId'] = $parentId;
                }
                $updateCategoryData = [
                    'odooId' => $odooId,
                    'shopwareId' => $updateCatData->getId(),
                    'odooParentId' => $updateData->parent_data->parent_id,
                    'shopwareParentId' => $updateCatData->getParentId(),

                ];
            } else {
                if (array_key_exists('name', $updateData)) {
                    $updateCategoryName = $updateData['name'];
                } else {
                    $updateCategoryName = $updateCatData->getName();
                }

                if (array_key_exists('parent_data', $updateData)) {
                    $parentId = $updateData['parent_data']['parent_id'];
                }
                $data = [
                    'id' => $updateCatData->getId(),
                    'name' => $updateCategoryName,
                    'active' => true,
                    'customFields' => ['odoo_category_id' => $odooId],
                ];
                $updateCategoryData = [
                    'odooId' => $odooId,
                    'shopwareId' => $updateCatData->getId()
                ];
            }
            $this->categoryRepository->upsert([$data], $context);
            $responseData[] = $updateCategoryData;
        }
        if ($responseData) {
            $responseDataArray = [
                'type' => 'Success',
                'responseCode' => '200',
                'updated_category_info' => $responseData,
            ];
        }
        return $responseDataArray;
    }

    public function findUpdateCategoryData($odooId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', $odooId));
        return $this->categoryRepository->search($criteria, $context)->first();
    }
}
