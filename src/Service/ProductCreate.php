<?php

namespace ICTECHOdooShopwareConnector\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class ProductCreate
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $propertyRepository,
        private readonly EntityRepository $optionRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly CategoryCreate $categoryCreate,
        private readonly TaxCreate $taxCreate,
        private readonly FileFetcher $fileFetcher,
        private readonly FileSaver $fileSaver,
        private readonly EntityRepository $mediaRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly ManufacturerCreate $manufacturerCreate,
        private readonly EntityRepository $manufacturerRepository,
    ) {
    }

    /**
     * @throws GuzzleException
     */
    public function getProductData($productData, $context): array
    {
        $newGeneratedTaxData = null;
        $response = $productMappingData = $productOptionIdArray = $productAvailable = $errorData = [];

        foreach ($productData as $productDataArray) {
            $productNumber = $productDataArray['default_code'];
            $childProducts = $optionIdsArray = $childProductMappingData = [];
            if (array_key_exists('tax_data', $productDataArray)) {
                $oddTaxData = $productDataArray['tax_data'];
                $taxData = $this->getTaxId($oddTaxData, $context);
                if (!$taxData) {
                    $taxDataCreate = $this->taxCreate->taxDataGenerate($oddTaxData, $context);
                    if ($taxDataCreate['type'] === 'Success' && $taxDataCreate['responseCode'] === 200) {
                        $taxId = $taxDataCreate['taxData'][0]['shopwareTaxId'];
                        $newGeneratedTaxData = $taxDataCreate['taxData'];
                    }
                } else {
                    $taxId = $taxData->getId();
                    $newGeneratedTaxData = [
                        'odooTaxId' => $oddTaxData[0]['id'],
                        'shopwareTaxId' => $taxId,
                    ];
                }
            }

            $netPrice = 0.0;
            $grossPrice = 0.0;
            // price calculation
            if (array_key_exists('sales_price', $productDataArray)) {
                $oddTaxData = $productDataArray['tax_data'];
                $taxData = $this->getTaxId($oddTaxData, $context);
                $odooPrice = $productDataArray['sales_price'];
                $netPrice = $odooPrice;
                $grossPrice = $odooPrice * $taxData->getTaxRate() / 100 + $odooPrice;
            }

            $propertyDataArray = null;
            if (array_key_exists('attribute_values_data', $productDataArray)) {
                $propertyDataArray = $productDataArray['attribute_values_data'];
            }

            if (array_key_exists('is_multiple_variant', $productDataArray)) {
                $isVariant = $productDataArray['is_multiple_variant'];
                $childrenData = null;
                // childValues
                if (array_key_exists('variant_detail_data', $productDataArray)) {
                    $childrenData = $productDataArray['variant_detail_data'];
                }
                if ($isVariant && $propertyDataArray && $childrenData) {
                    $properties = $this->generatePropertyData($propertyDataArray, $context);
                    //$this->getUniqueOptions($properties);
                    foreach ($childrenData as $childData) {
                        $childProperties = $childData['variant_attribute_values_data'];
                        if ($childProperties) {
                            $childDataArray = $this->getChildrenData($childProperties, $childData, $taxData, $context);
                            foreach ($childDataArray['options'] as $item) {
                                $optionIdsArray[] = [
                                    'optionId' => $item['id']
                                ];
                            }
                            $childProducts[] = $childDataArray;
                        }
                    }
                }
            }

            // unique array for configuratorSettings for variant product
            $optionIds = array_map(function ($item) {
                return $item['optionId'];
            }, $optionIdsArray);

            // Get unique optionIds
            $uniqueOptionIds = array_unique($optionIds);

            $shopwareCategoryId = $categoryDataMain = $shopwareManufacturerId = [];

            // category data for product category
            if (array_key_exists('category_data', $productDataArray)) {
                $categoryData = $productDataArray['category_data'];
                if ($categoryData) {
                    foreach ($categoryData as $categoryArray) {
                        $catId = $categoryArray['id'];
                        $shopwareCategoryData = $this->getCategoryData($catId, $context);
                        if ($shopwareCategoryData) {
                            $categoryDataMain[] = [
                                'id' => $shopwareCategoryData->getId(),
                            ];
                            $shopwareCategoryId[] = [
                                'odooCategoryId' => $catId,
                                'shopwareCategoryId' => $shopwareCategoryData->getId()
                            ];
                        } else {
                            $this->categoryCreate->categoryInsert($catId, $context);
                            $shopwareCategoryData = $this->getCategoryData($catId, $context);
                            if (isset($shopwareCategoryData)) {
                                $categoryDataMain[] = [
                                    'id' => $shopwareCategoryData->getId()
                                ];
                                $shopwareCategoryId[] = [
                                    'odooCategoryId' => $catId,
                                    'shopwareCategoryId' => $shopwareCategoryData->getId()
                                ];
                            }
                        }
                    }
                }
            }

            // manufacturer data for product category
            if (array_key_exists('brand_data', $productDataArray)) {
                $manufacturerData = $productDataArray['brand_data'];
                if ($manufacturerData) {
                    foreach ($manufacturerData as $manufacturerArray) {
                        $manufacturerId = $manufacturerArray['id'];
                        $shopwareManufacturerData = $this->getManufacturerData($manufacturerId, $context);
                        if ($shopwareManufacturerData) {
                            $manufacturerDataMain = [
                                'id' => $shopwareManufacturerData->getId(),
                            ];
                            $shopwareManufacturerId[] = [
                                'odooManufacturerId' => $manufacturerId,
                                'shopwareManufacturerId' => $shopwareManufacturerData->getId()
                            ];
                        } else {
                            $this->manufacturerCreate->manufacturerDataGenerate($manufacturerData, $context);
                            $shopwareManufacturerData = $this->getManufacturerData($manufacturerId, $context);
                            if (isset($shopwareManufacturerData)) {
                                $manufacturerDataMain = [
                                    'id' => $shopwareManufacturerData->getId()
                                ];
                                $shopwareManufacturerId[] = [
                                    'odooManufacturerId' => $manufacturerId,
                                    'shopwareManufacturerId' => $shopwareManufacturerData->getId()
                                ];
                            }
                        }
                    }
                }
            }

            $mediaIds = [];
            $checkExistsProductData = $this->checkProductExist($productDataArray, $context);
            if ($checkExistsProductData && $checkExistsProductData->getConfiguratorSettings()) {
                $productConfiguratorSettings = $checkExistsProductData->getConfiguratorSettings()->getElements();
                foreach ($productConfiguratorSettings as $productConfiguratorSetting) {
                    $productOptionIdArray[] = $productConfiguratorSetting->getOptionId();
                }
            }

            if ($productOptionIdArray) {
                $uniqueConfiguratorSettingsOptionIds = array_diff($uniqueOptionIds, $productOptionIdArray);
            } else {
                $uniqueConfiguratorSettingsOptionIds = $uniqueOptionIds;
            }

            if ($uniqueConfiguratorSettingsOptionIds) {
                $uniqueOptionsIdsArray = array_map(function ($id) {
                    return ['optionId' => $id];
                }, $uniqueConfiguratorSettingsOptionIds);
            }
            if (array_key_exists('image_url', $productDataArray)) {
                $imageUrlArray = $productDataArray['image_url'];
                $mediaIds = $this->mediaUpload($imageUrlArray, $checkExistsProductData, $context);
                $coverImage = null;
                foreach ($mediaIds as $mediaId) {
                    $coverImage = $mediaId['mediaId'];
                }
            }
            if (array_key_exists('weight', $productDataArray)) {
                $weight = $productDataArray['weight'];
            }
            if (array_key_exists('description', $productDataArray) && $productDataArray['description'] !== 'False') {
                $productDescription = $productDataArray['description'];
            }

            $productSalesChannel = $this->pluginConfig->getProductSalesChannelId();
            $salesChannelId = [];
            if ($productSalesChannel) {
                foreach ($productSalesChannel as $salesChannel) {
                    $salesChannelId[] = [
                        'salesChannelId' => $salesChannel,
                        'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                    ];
                }
            }
            if (!$checkExistsProductData) {
                $data = [
                    'id' => Uuid::randomHex(),
                    'productNumber' => $productNumber,
                    'active' => boolval($productDataArray['active'] ?? false),
                    'taxId' => $taxId,
                    'name' => $productDataArray['name'],
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => $grossPrice, 'net' => $netPrice, 'linked' => false]],
                    'children' => $childProducts,
                    'stock' => 0,
                    'customFields' => ['odoo_product_id' => $productDataArray['id']],
                ];
                if (isset($manufacturerDataMain)) {
                    $data['manufacturer'] = $manufacturerDataMain;
                }
                if (isset($categoryDataMain)) {
                    $data['categories'] = $categoryDataMain;
                }
                if (isset($coverImage)) {
                    $data['coverId'] = $coverImage;
                }
                if (isset($salesChannelId)) {
                    $data['visibilities'] = $salesChannelId;
                }
                if (isset($mediaIds)) {
                    $data['media'] = $mediaIds;
                }

                if (isset($uniqueOptionsIdsArray)) {
                    $data['configuratorSettings'] = $uniqueOptionsIdsArray;
                }
                if (isset($productDescription)) {
                    $data['description'] = $productDescription;
                }
                if (isset($weight)) {
                    $data['weight'] = $weight;
                }
                try {
                    $this->productRepository->upsert([$data], $context);
                } catch (Exception $e) {
                    $errorData[] = [
                        'odooId' => $productDataArray['id'],
                        'message' => $e->getMessage(),
                    ];
                }

                if ($childProducts) {
                    foreach ($childProducts as $childProduct) {
                        $childProductMappingData[] =
                            [
                                'odooTemplateId' => $childProduct['customFields']['odoo_product_id'],
                                'shopwareVariantId' => $childProduct['id']
                            ];
                    }
                }
                $productMappingData['mainProductId'][] = [
                    'odooTemplateId' => $productDataArray['id'],
                    'shopwareMainProductId' => $data['id'],
                    'productCategoryIds' => $shopwareCategoryId,
                    'productManufacturerIds' => $shopwareManufacturerId,
                    'taxData' => $newGeneratedTaxData,
                    'variantProductId' => $childProductMappingData,
                ];
            } else {
                $productAvailable['mainProductId'][] = [
                    'odooTemplateId' => $productDataArray['id'],
                    'shopwareMainProductId' => $checkExistsProductData ? $checkExistsProductData->getId() : '',
                ];
            }
        }

        if ($productMappingData) {
            $response['type'] = 'Success';
            $response['responseCode'] = 200;
            $response['responseData'] = $productMappingData;
            $response['errorProducts'] = $errorData;
        } else {
            $response['type'] = 'Success';
            $response['responseCode'] = 200;
            $response['message'] = 'Product is already created';
            $response['responseData'] = $productAvailable;
        }
        return $response;
    }

    public function getTaxId($oddTaxData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_tax_id', $oddTaxData[0]['id']));
        return $this->taxRepository->search($criteria, $context)->first();
    }

    public function generatePropertyData($propertyDataArray, $context): array
    {
        $propertyIds = [];
        foreach ($propertyDataArray as $propertyOptionName) {
            $propertyGroup = $propertyOptionName['attribute']['name'];
            $propertyGroupValues = $propertyOptionName['attribute']['values_data'];
            $childPropertyAttributeData = $this->getPropertyData($propertyGroup, $context);
            $childPropertyId = null;

            $childProperty = [
                'id' => $childPropertyAttributeData ? $childPropertyAttributeData->getId() : Uuid::randomHex(),
                'colorHexCode' => '',
                'name' => $childPropertyAttributeData ? $childPropertyAttributeData->getName() : $propertyGroup,
            ];
            $this->propertyRepository->upsert([$childProperty], $context);
            $childPropertyId = $this->getPropertyData($propertyGroup, $context);

            foreach ($propertyGroupValues as $propertyGroupValue) {
                $propertyGroupValueName = $propertyGroupValue['name'];
                $childPropertyValueData = $this->getPropertyOptionData($propertyGroupValueName, $context);
                $propertyDataSubArray = [
                    'id' => $childPropertyValueData ? $childPropertyValueData->getId() : Uuid::randomHex(),
                    'name' => $childPropertyValueData ? $childPropertyValueData->getName() : $propertyGroupValueName,
		         'displayType' => 'text',
                    'sortingType' => 'alphanumeric',
                    'group' => [
                        'id' => $childPropertyAttributeData ? $childPropertyAttributeData->getId() : $childPropertyId->getId(),
                        'colorHexCode' => '',
                        'name' => $childPropertyAttributeData ? $childPropertyAttributeData->getName() : $propertyGroup,
                    ],
                ];
                $propertyIds[] = [
                    'id' => $childPropertyAttributeData ? $childPropertyAttributeData->getId() : $childPropertyId,
                ];
                try {
                    $this->optionRepository->upsert([$propertyDataSubArray], $context);
                } catch (Exception $exception) {
                    $response = [
                        'type' => 'Error',
                        'responseCode' => $exception->getstatusCode(),
                        'message' => $exception->getMessage(),
                    ];
                }
            }

        }
        return $response ?? $propertyIds;
    }

    public function getPropertyData($propertyName, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $propertyName));
        return $this->propertyRepository->search($criteria, $context)->first();
    }

    public function getPropertyOptionData($name, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        return $this->optionRepository->search($criteria, $context)->first();
    }

    public function getUniqueOptions($properties): array
    {
        // Extract the optionIds into a flat array
        $unique = [];
//        array_unique($properties);
        foreach ($properties as $item) {
        //dd($properties,$item);
        	
        //    if (!array_key_exists($item['id'], $unique)) {
                $unique[$item['id']] = $item;
          //  }
        }
//        dd($unique);
        return array_values($unique);
    }

    public function getChildrenData($childProperties, $childData, $taxDataParent, $context): array
    {
        $childProductDataArray = [];
        foreach ($childProperties as $childProperty) {
            $childPropertyOptionName = $childProperty['attribute']['name'];
            $childPropertyValueName = $childProperty['value']['name'];
            $this->getPropertyData($childPropertyOptionName, $context);
            $propertyOptionData = $this->getPropertyOptionData($childPropertyValueName, $context);
            $childProductDataArray[] = [
                'id' => $propertyOptionData ? $propertyOptionData->getId() : Uuid::randomHex(),
            ];
        }
        if (array_key_exists('tax_data', $childData)) {
            $oddTaxData = $childData['tax_data'];
            $taxData = $this->getTaxId($oddTaxData, $context);
            if (!$taxData) {
                $taxData = $this->taxCreate->taxDataGenerate($oddTaxData[0], $context);
                if ($taxData['type'] === 'Success' && $taxData['responseCode'] === 200) {
                    $taxId = $taxData['taxData']['shopwareId'];
                    $newGeneratedTaxData = $taxData['taxData'];
                }
            } else {
                $taxId = $taxData->getId();
                $newGeneratedTaxData = [
                    'odooId' => $taxId,
                    'shopwareId' => $taxId,
                ];
            }
        } else {
            $taxData = $taxDataParent;
        }
        // manufacturer data for product category
        if (array_key_exists('brand_data', $childData)) {
            $manufacturerData = $childData['brand_data'];
            if ($manufacturerData) {
                foreach ($manufacturerData as $manufacturerArray) {
                    $manufacturerId = $manufacturerArray['id'];
                    $shopwareManufacturerData = $this->getManufacturerData($manufacturerId, $context);
                    if ($shopwareManufacturerData) {
                        $manufacturerChildDataMain = [
                            'id' => $shopwareManufacturerData->getId(),
                        ];
                    } else {
                        $this->manufacturerCreate->manufacturerDataGenerate($manufacturerData, $context);
                        $shopwareManufacturerData = $this->getManufacturerData($manufacturerId, $context);
                        if (isset($shopwareManufacturerData)) {
                            $manufacturerChildDataMain = [
                                'id' => $shopwareManufacturerData->getId()
                            ];
                        }
                    }
                }
            }
        }

        if (array_key_exists('sales_price', $childData)) {
            $odooPrice = $childData['sales_price'];
            $netPrice = $odooPrice;
            $grossPrice = $odooPrice * $taxData->getTaxRate() / 100 + $odooPrice;
        }
        $childProductNumber = $childData['default_code'];
        $childProductData = $this->checkChildrenProductExist($childData, $context);
        if (array_key_exists('description', $childData) && $childData['description'] !== 'False') {
            $productDescription = $childData['description'];
        } else {
            $productDescription = null;
        }
        return [
            'id' => $childProductData ? $childProductData->getId() : Uuid::randomHex(),
            'productNumber' => $childProductNumber,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => $grossPrice, 'net' => $netPrice, 'linked' => false]],
            'stock' => 0,
            'options' => $childProductDataArray,
            'customFields' => ['odoo_product_id' => $childData['id']],
//            'manufacturer' => $manufacturerChildDataMain ?? '',
            'description' => $productDescription
        ];
    }

    public function checkChildrenProductExist($productDataArray, $context): ?Entity
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $productDataArray['id']));
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function getCategoryData($catId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', $catId));
        return $this->categoryRepository->search($criteria, $context)->first();
    }

    public function getManufacturerData($manufacturerId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_manufacturer_id', $manufacturerId));
        return $this->manufacturerRepository->search($criteria, $context)->first();
    }

    public function checkProductExist($productDataArray, $context): ?Entity
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addAssociation('configuratorSettings');
        $criteria->addAssociation('children');
        $criteria->addAssociation('children.configuratorSettings');
        $criteria->addAssociation('price');
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $productDataArray['id']));
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function mediaUpload($imageUrlArray, $checkExistsProductData, $context): array
    {
        $mediaIds = [];
        if (!$checkExistsProductData) {
            $productId = Uuid::randomHex();
        } else {
            $productId = $checkExistsProductData->getId();
        }
        foreach ($imageUrlArray as $url) {
            $ext = pathinfo($url, PATHINFO_EXTENSION);
            $fileName = basename($url);

            $request = new Request(['url' => $url]);
            $request = new Request(['extension' => $ext]);
            $request->request->set('url', $url);
            $request->request->set('extension', $ext);
            $file = $this->fileFetcher->fetchFileFromURL($request, $fileName);

            $explodedFileName = explode('.', $fileName);
            unset($explodedFileName[count($explodedFileName) - 1]);

            $fileName = $productId . '_' . implode('.', $explodedFileName);
            $searchMedia = $this->mediaRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('fileName', $fileName)),
                $context
            )->first();

            if (!$searchMedia) {
                $mediaId = Uuid::randomHex();
                $media = [
                    'id' => $mediaId,
                    'fileSize' => $file->getFileSize(),
                    'fileName' => $fileName,
                    'mimeType' => $file->getMimeType(),
                    'fileExtension' => $ext,
                ];

                $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($media): void {
                    $this->mediaRepository->upsert([$media], $context);
                });
                try {
                    $this->upload($file, $fileName, $mediaId, $context);
                } catch (Exception) {
                    $fileName = $fileName .= $mediaId;
                    $this->upload($file, $fileName, $mediaId, $context);
                }

                $mediaIds[] = [
                    'id' => $media['id'],
                    'mediaId' => $mediaId
                ];
            } else {
                $mediaIds[] = [
                    'id' => $searchMedia->getId(),
                    'mediaId' => $searchMedia->getId()
                ];
            }
        }
        return $mediaIds;
    }

    public function upload($file, $fileName, $mediaId, $context): void
    {
        $this->fileSaver->persistFileToMedia(
            $file,
            $fileName,
            $mediaId,
            $context
        );
    }
}
