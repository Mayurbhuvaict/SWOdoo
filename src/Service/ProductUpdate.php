<?php

namespace ICTECHOdooShopwareConnector\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

class ProductUpdate
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly CategoryCreate $categoryCreate,
        private readonly TaxCreate $taxCreate,
        private readonly ProductCreate $productCreate,
        private readonly EntityRepository $propertyRepository,
        private readonly EntityRepository $optionRepository,
        private readonly FileFetcher $fileFetcher,
        private readonly FileSaver $fileSaver,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $productMediaRepository,
        private readonly EntityRepository $productCategoryRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly ManufacturerCreate $manufacturerCreate,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $productVisibilityRepository,
    ) {
    }

    public function getUpdateProductData($context, $request): array
    {
        $updatedProductData = json_decode($request->getContent(), true);
        $updatedProductTemplateData = $updatedProductData['structure'];
        if (array_key_exists('template', $updatedProductTemplateData)) {
            $updatedTemplateCommonInfo = $updatedProductData['updated_template_common_info'];
            $updatedTemplateSeperatedInfo = $updatedProductData['updated_template_separated_info'];
            $mainOdooProductArrayInfo = [
                'updatedTemplateCommonInfo' => $updatedTemplateCommonInfo,
                'updatedTemplateSeperatedInfo' => $updatedTemplateSeperatedInfo
            ];
            if (array_key_exists('updatedTemplateCommonInfo', $mainOdooProductArrayInfo)) {
                $updatedTemplateCommonInfo = $mainOdooProductArrayInfo['updatedTemplateCommonInfo'];
                if ($updatedTemplateCommonInfo) {
                    if (array_key_exists('product_image_url', $updatedTemplateSeperatedInfo)) {
                        foreach ($updatedTemplateSeperatedInfo['product_image_url'] as $imageData) {
                            if (array_key_exists('image_url', $imageData)) {
                                $mainProductData = $this->createImageData($updatedTemplateSeperatedInfo, $context);
                            }
                        }
                    } else {
                        $mainProductData = $this->updateProductData($updatedTemplateCommonInfo, $context, $request);
                    }
                }
            }
            if (array_key_exists('updatedTemplateSeperatedInfo', $mainOdooProductArrayInfo)) {
                $updatedTemplateSeperatedInfo = $mainOdooProductArrayInfo['updatedTemplateSeperatedInfo'];
                if ($updatedTemplateSeperatedInfo) {
                    if (array_key_exists('product_image_url', $updatedTemplateSeperatedInfo)) {
                        foreach ($updatedTemplateSeperatedInfo['product_image_url'] as $imageData) {
                            if (array_key_exists('image_url', $imageData)) {
                                $childProductData = $this->createImageData($imageData, $context);
                            }
                        }
                    } else {
                        $childProductData = $this->updateChildProductData($updatedTemplateSeperatedInfo, $context);
                    }
                }
            }
        }

        if (array_key_exists('variant', $updatedProductTemplateData)) {
            $variantData = $updatedProductData['updated_variant_info'];
            if (array_key_exists('product_image_url', $variantData)) {
                foreach ($variantData['product_image_url'] as $imageData) {
                    if (array_key_exists('image_url', $imageData)) {
                        $mainProductData = $this->createImageData($imageData, $context);
                    }
                }
            } else {
                $mainProductData = $this->updateProductData($variantData, $context, $request);
            }
        }
        if (isset($childProductData)) {
            if (array_key_exists('message', $childProductData)) {
                unset($childProductData['message']);
                $response = [
                    'type' => 'Error',
                    'responseCode' => 404,
                    'message' => 'Product not found',
                    'productData' => $childProductData
                ];
            } else {
                $response = [
                    'type' => 'Success',
                    'responseCode' => 200,
                    'productData' => $childProductData
                ];
            }
        } else {
            $response = [
                'type' => 'Success',
                'responseCode' => 200,
                'productData' => $mainProductData
            ];
        }
        return $response;
    }

    /**
     * @throws GuzzleException
     */
    public function updateProductData($updatedChildProductData, $context, $request): array
    {
        foreach ($updatedChildProductData as $productDataArray) {
            $shopwareProductId = null;
            $shopwareProductDataArray = $responseData = $newGeneratedTaxData = $shopwareManufacturerId = [];

            if (array_key_exists('shopware_product_tmpl_id', $productDataArray)) {
                $shopwareProductId = $productDataArray['shopware_product_tmpl_id'];
                $odooProductId = $productDataArray['id'];
                $shopwareProductDataArray = $this->findProductDataById($shopwareProductId, $odooProductId, $context);
            }
            if (array_key_exists('shopware_product_id', $productDataArray)) {
                $shopwareProductId = $productDataArray['shopware_product_id'];
                $odooProductId = $productDataArray['id'];
                $shopwareProductDataArray = $this->findProductDataById($shopwareProductId, $odooProductId, $context);
            }
            if ($shopwareProductDataArray) {
                if (array_key_exists('default_code', $productDataArray)) {
                    $productNumber = $productDataArray['default_code'];
                } else {
                    $productNumber = $shopwareProductDataArray->getProductNumber();
                }
                if (array_key_exists('tax_data', $productDataArray)) {
                    $oddTaxData = $productDataArray['tax_data'];
                    $taxData = $this->getTaxId($oddTaxData, $context);
                    if (! $taxData) {
                        $taxDataCreate = $this->taxCreate->taxDataGenerate($oddTaxData[0], $context);
                        if ($taxDataCreate['type'] === 'Success' && $taxDataCreate['responseCode'] === 200) {
                            $taxId = $taxDataCreate['taxData'][0]['shopwareTaxId'];
                            $newGeneratedTaxData = $taxDataCreate['taxData'];
                        }
                        $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                        $updateNetPrice = $shopwarePrice->getNet();
                        $updateGrossPrice = $updateNetPrice * $oddTaxData[0]['amount'] / 100 + $updateNetPrice;
                    } else {
                        $taxId = $taxData->getId();
                        $newGeneratedTaxData = [
                            'odooTaxId' => $oddTaxData[0]['id'],
                            'shopwareTaxId' => $taxId,
                        ];
                        $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                        $updateNetPrice = $shopwarePrice->getNet();
                        $updateGrossPrice = $updateNetPrice * $taxData->getTaxRate() / 100 + $updateNetPrice;
                    }
                } else {
                    $taxId = $shopwareProductDataArray->getTaxId();
                    $newGeneratedTaxData = [
                        'odooTaxId' => $shopwareProductDataArray->getTax()->getCustomFields()['odoo_tax_id'],
                        'shopwareTaxId' => $taxId,
                    ];
                }
                // price calculation
                if (array_key_exists('sales_price', $productDataArray)) {
                    $taxData = $shopwareProductDataArray->getTax();
                    $odooPrice = $productDataArray['sales_price'];
                    $netPrice = $odooPrice;
                    $grossPrice = $odooPrice * $taxData->getTaxRate() / 100 + $odooPrice;
                } else {
                    $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                    $netPrice = $shopwarePrice->getNet();
                    $grossPrice = $shopwarePrice->getGross();
                }

                if (array_key_exists('stock', $productDataArray)) {
                    $productStock = $productDataArray['stock'];
                } else {
                    $productStock = $shopwareProductDataArray->getStock();
                }
                if (array_key_exists('name', $productDataArray)) {
                    $productName = $productDataArray['name'];
                } else {
                    $productName = $shopwareProductDataArray->getName();
                }
                if (array_key_exists('description', $productDataArray) && $productDataArray['description'] !== 'False') {
                    $productDescription = $productDataArray['description'];
                }
                if (array_key_exists('active', $productDataArray)) {
                    $productActive = $productDataArray['active'];
                } else {
                    $productActive = $shopwareProductDataArray->getActive();
                }

                $shopwareCategoryId = $categoryDataMain = [];

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
                        $categoryRemoveData = [
                            'id' => $shopwareProductDataArray->getId(),
                            'categoryId' => null,
                        ];
                        $this->productRepository->upsert([$categoryRemoveData], $context);
                    }
                }

                $mediaIds = [];
                if (array_key_exists('image_url', $productDataArray)) {
                    $imageUrlArray = $productDataArray['image_url'];
                    if ($imageUrlArray) {
                        $mediaIds = $this->mediaUpload($imageUrlArray, $shopwareProductDataArray, $context);
                        $coverImage = null;
                        $this->removeProductMedia($shopwareProductId, $context);
                        foreach ($mediaIds as $mediaId) {
                            $coverImage = $mediaId['mediaId'];
                        }
                    } else {
                        $this->removeProductMedia($shopwareProductId, $context);
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

                if (array_key_exists('deleted_brand_data', $productDataArray) && $productDataArray['deleted_brand_data']) {
                    $this->productRepository->upsert(
                        [
                            [
                                'id' => $shopwareProductDataArray->getId(),
                                'manufacturerId' => null,
                            ]
                        ],
                        $context
                    );
                }

                if (array_key_exists('weight', $productDataArray)) {
                    $productWeight = $productDataArray['weight'];
                }
                if (array_key_exists('description', $productDataArray) && $productDataArray['description'] !== 'False') {
                    $productDescription = $productDataArray['description'];
                }
                if (isset($updateNetPrice) && isset($updateGrossPrice)) {
                    $grossPrice = $updateGrossPrice;
                    $netPrice = $updateNetPrice;
                }
                $productSalesChannel = $this->pluginConfig->getProductSalesChannelId();
                $salesChannelId = [];

                if ($productSalesChannel) {
                    $this->removeProductFromSalesChannel($shopwareProductDataArray, $context);
                    foreach ($productSalesChannel as $salesChannel) {
                        $salesChannelId[] = [
                            'salesChannelId' => $salesChannel,
                            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                        ];
                    }
                }
               
                $updateProductData = [
                    'id' => $shopwareProductDataArray->getId(),
                    'productNumber' => $productNumber,
                    'active' => $productActive,
                    'taxId' => $taxId,
                    'name' => $productName,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => $grossPrice, 'net' => $netPrice, 'linked' => false]],
                    'stock' => $productStock,
                    'customFields' => ['odoo_product_id' => $odooProductId],
                ];
                if (isset($productDescription)) {
                    $updateProductData['description'] = $productDescription;
                }
                if (isset($salesChannelId)) {
                    $updateProductData['visibilities'] = $salesChannelId;
                }
                if ($mediaIds) {
                    $updateProductData['media'] = $mediaIds;
                }
                if (isset($productWeight)) {
                    $updateProductData['weight'] = $productWeight;
                }
                if (isset($coverImage)) {
                    $updateProductData['coverId'] = $coverImage;
                }
//                if (isset($categoryDataMain)) {
                if (! empty($categoryDataMain)) {
                 $categoryIdRemove = $shopwareProductDataArray->getCategoryIds();
                if ($categoryIdRemove) {
                    foreach ($categoryIdRemove as $categoryId) {
                        $this->productCategoryRepository->delete(
                            [
                                [
                                    'productId' => $shopwareProductDataArray->getId(),
                                    'categoryId' => $categoryId
                                ]
                            ],
                            $context
                        );
                    }
                }
                    $updateProductData['categories'] = $categoryDataMain;
                }
                if (isset($manufacturerDataMain)) {
                    $updateProductData['manufacturer'] = $manufacturerDataMain;
                }
                $this->productRepository->upsert([$updateProductData], $context);

                $productMappingData = [
                    'odooTemplateId' => $odooProductId,
                    'shopwareMainProductId' => $updateProductData['id'],
                ];
                if (isset($shopwareManufacturerId) && $shopwareManufacturerId) {
                    $productMappingData['productManufacturerIds'] = $shopwareManufacturerId;
                }

                if (isset($newGeneratedTaxData)) {
                    $productMappingData['taxData'] = $newGeneratedTaxData;
                }
                if (isset($shopwareCategoryId) && $shopwareCategoryId) {
                    $productMappingData['productCategoryIds'] = $shopwareCategoryId;
                }
            } else {
                $productMappingData = [
                    'message' => 'Product not found'
                ];
            }
        }
        if ($productMappingData) {
            $responseData[] = $productMappingData;
        }
        return $responseData;
    }

    public function findProductDataById($shopwareProductId, $odooProductId, $context): ?Entity
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addAssociation('configuratorSettings');
        $criteria->addAssociation('children');
        $criteria->addAssociation('price');
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('visibilities');
        $criteria->addFilter(new EqualsFilter('id', $shopwareProductId));
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $odooProductId));
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function getTaxId($oddTaxData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_tax_id', $oddTaxData[0]['id']));
        return $this->taxRepository->search($criteria, $context)->first();
    }

    public function getCategoryData($catId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', $catId));
        return $this->categoryRepository->search($criteria, $context)->first();
    }

    public function updateChildProductData($odooProductArrayInfo, $context): array
    {
        $response = $childOdooIdArray = $deleteProductArray = [];
        foreach ($odooProductArrayInfo as $productTemplateDataArray) {
            $shopwareProductId = $isVariant = $propertyDataArray = $childrenData = null;
            $shopwareProductDataArray = $childProductMappingData = [];
            if (array_key_exists('shopware_product_tmpl_id', $productTemplateDataArray)) {
                $shopwareProductId = $productTemplateDataArray['shopware_product_tmpl_id'];
                $odooProductId = $productTemplateDataArray['id'];
                $this->productRepository->update([
                    [
                        'id' => $shopwareProductId,
                        'stock' => 0,
                    ]
                ], $context);
                $shopwareProductDataArray = $this->findProductDataById($shopwareProductId, $odooProductId, $context);
            } else {
                if (array_key_exists('shopware_product_tmpl_id', $productTemplateDataArray)) {
                    $shopwareProductId = $productDataArray['shopware_product_tmpl_id'];
                    $odooProductId = $productDataArray['id'];
                    $shopwareProductDataArray = $this->findProductDataById($shopwareProductId, $odooProductId, $context);
                }
            }
            if (array_key_exists('template_variant_data', $productTemplateDataArray)) {
                $productDataArray = $productTemplateDataArray['template_variant_data'];
            } else {
                $productDataArray = $productTemplateDataArray;
            }
            if ($shopwareProductDataArray) {
                if (array_key_exists('default_code', $productDataArray)) {
                    $productNumber = $productDataArray['default_code'];
                } else {
                    $productNumber = $shopwareProductDataArray->getProductNumber();
                }

                if (array_key_exists('tax_data', $productDataArray)) {
                    $oddTaxData = $productDataArray['tax_data'];
                    $taxData = $this->getTaxId($oddTaxData, $context);
                    if (! $taxData) {
                        $taxDataCreate = $this->taxCreate->taxDataGenerate($oddTaxData[0], $context);
                        if ($taxDataCreate['type'] === 'Success' && $taxDataCreate['responseCode'] === 200) {
                            $taxId = $taxDataCreate['taxData'][0]['shopwareTaxId'];
                            $newGeneratedTaxData = $taxDataCreate['taxData'];
                            $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                            $updateNetPrice = $shopwarePrice->getNet();
                            $updateGrossPrice = $updateNetPrice * $oddTaxData[0]['amount'] / 100 + $updateNetPrice;
                        }
                    } else {
                        $taxId = $taxData->getId();
                        $newGeneratedTaxData = [
                            'odooTaxId' => $oddTaxData[0]['id'],
                            'shopwareTaxId' => $taxId,
                        ];
                        $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                        $updateNetPrice = $shopwarePrice->getNet();
                        $updateGrossPrice = $updateNetPrice * $taxData->getTaxRate() / 100 + $updateNetPrice;
                    }
                } else {
                    $taxId = $shopwareProductDataArray->getTaxId();
                    $newGeneratedTaxData = [
                        'odooTaxId' => $shopwareProductDataArray->getTax()->getCustomFields()['odoo_tax_id'],
                        'shopwareTaxId' => $taxId,
                    ];
                }

                // price calculation
                if (array_key_exists('sales_price', $productDataArray)) {
                    $taxData = $shopwareProductDataArray->getTax();
                    $odooPrice = $productDataArray['sales_price'];
                    $netPrice = $odooPrice;
                    $grossPrice = $odooPrice * $taxData->getTaxRate() / 100 + $odooPrice;
                } else {
                    $shopwarePrice = $shopwareProductDataArray->getPrice()->first();
                    $netPrice = $shopwarePrice->getNet();
                    $grossPrice = $shopwarePrice->getGross();
                }
                if (array_key_exists('stock', $productDataArray)) {
                    $productStock = $productDataArray['stock'];
                } else {
                    $productStock = $shopwareProductDataArray->getStock();
                }

                if (array_key_exists('name', $productDataArray)) {
                    $productName = $productDataArray['name'];
                } else {
                    $productName = $shopwareProductDataArray->getName();
                }
                if (array_key_exists('active', $productDataArray)) {
                    $productActive = boolval($productDataArray['active']);
                } else {
                    $productActive = boolval($shopwareProductDataArray->getActive());
                }

                $shopwareCategoryId = $categoryDataMain = $optionIdsArray = $childProducts = $childTax = $mediaIds = $productMappingData = [];
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
                if (array_key_exists('image_url', $productDataArray)) {
                    $imageUrlArray = $productDataArray['image_url'];
                    if ($imageUrlArray) {
                        $this->removeProductMedia($shopwareProductId, $context);
                        $mediaIds = $this->mediaUpload($imageUrlArray, $shopwareProductDataArray, $context);
                        $coverImage = null;
                        foreach ($mediaIds as $mediaId) {
                            $coverImage = $mediaId['mediaId'];
                        }
                    } else {
                        $this->removeProductMedia($shopwareProductId, $context);
                    }
                }

                if (array_key_exists('weight', $productDataArray)) {
                    $productWeight = $productDataArray['weight'];
                }
                if (array_key_exists('description', $productDataArray) && $productDataArray['description'] !== 'False') {
                    $productDescription = $productDataArray['description'];
                }
                if (array_key_exists('is_multiple_variant', $productDataArray)) {
                    $isVariant = $productDataArray['is_multiple_variant'];
                }
                if (array_key_exists('attribute_values_data', $productDataArray)) {
                    $propertyDataArray = $productDataArray['attribute_values_data'];
                }

                if (array_key_exists('variant_detail_data', $productDataArray)) {
                    $childrenData = $productDataArray['variant_detail_data'];
                }

                if ($isVariant && $propertyDataArray && $childrenData) {
                    $properties = $this->generatePropertyData($propertyDataArray, $context);
//                    $this->productCreate->getUniqueOptions($properties);
                    foreach ($childrenData as $childData) {
                        $childProperties = $childData['variant_attribute_values_data'];
                        if ($childProperties) {
                            foreach ($oddTaxData as $oddTaxDataArray) {
                                $childTax[] = $oddTaxDataArray;
                            }
                            $taxArray = $this->getTaxId($childTax, $context);
                            $childDataArray = $this->getChildrenData($childProperties, $childData, $taxArray, $context);

                            if (array_key_exists('options', $childDataArray)) {
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

                $productOptionIdArray = [];
                if ($shopwareProductDataArray && $shopwareProductDataArray->getConfiguratorSettings()) {
                    $productConfiguratorSettings = $shopwareProductDataArray->getConfiguratorSettings()->getElements();
                    foreach ($productConfiguratorSettings as $productConfiguratorSetting) {
                        $productOptionIdArray[] = $productConfiguratorSetting->getOptionId();
                    }
                }
                if ($productOptionIdArray) {
                    $uniqueConfiguratorSettingsOptionIds = array_diff($uniqueOptionIds, $productOptionIdArray);
                } else {
                    $uniqueConfiguratorSettingsOptionIds = $uniqueOptionIds;
                }

                $productSalesChannel = $this->pluginConfig->getProductSalesChannelId();
                $salesChannelId = [];

                if ($productSalesChannel) {
                    $this->removeProductFromSalesChannel($shopwareProductDataArray, $context);
                    foreach ($productSalesChannel as $salesChannel) {
                        $salesChannelId[] = [
                            'salesChannelId' => $salesChannel,
                            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                        ];
                    }
                }


                // Convert back to the original associative array format
                $uniqueOptionsIdsArray = array_map(function ($id) {
                    return ['optionId' => $id];
                }, $uniqueConfiguratorSettingsOptionIds);
                if (isset($updateNetPrice) && isset($updateGrossPrice)) {
                    $grossPrice = $updateGrossPrice;
                    $netPrice = $updateNetPrice;
                }
                $updateProductData = [
                    'id' => $shopwareProductDataArray->getId(),
                    'productNumber' => $productNumber,
                    'active' => $productActive,
                    'taxId' => $taxId,
                    'name' => $productName,
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => $grossPrice, 'net' => $netPrice, 'linked' => false]],
                    'configuratorSettings' => $uniqueOptionsIdsArray,
                    'stock' => $productStock,
                    'customFields' => ['odoo_product_id' => $odooProductId],
                ];
                if (isset($salesChannelId)) {
                    $updateProductData['visibilities'] = $salesChannelId;
                }
                if (isset($childProducts)) {
                    $updateProductData['children'] = $childProducts;
                }
                if (isset($productDescription)) {
                    $updateProductData['description'] = $productDescription;
                }
                if ($mediaIds) {
                    $updateProductData['media'] = $mediaIds;
                }
                if (isset($productWeight)) {
                    $updateProductData['weight'] = $productWeight;
                }
                if (isset($coverImage)) {
                    $updateProductData['coverId'] = $coverImage;
                }
//                if (isset($categoryDataMain)) {
                if (! empty($categoryDataMain)) {
                 $categoryIdRemove = $shopwareProductDataArray->getCategoryIds();
                    if ($categoryIdRemove) {
                        foreach ($categoryIdRemove as $categoryId) {
                            $this->productCategoryRepository->delete(
                                [
                                    [
                                        'productId' => $shopwareProductDataArray->getId(),
                                        'categoryId' => $categoryId
                                    ]
                                ],
                                $context
                            );
                        }
                    }
                    $updateProductData['categories'] = $categoryDataMain;
                }
                if (array_key_exists('deleted_variant_data', $productDataArray)) {
                    $deleteProductData = $productDataArray['deleted_variant_data'];
                    foreach ($deleteProductData as $deleteProductInfo) {
                        $productId = $this->getProductDataById($deleteProductInfo, $context);
                        if ($productId) {
                            $this->productRepository->delete([['id' => $productId->getId()]], $context);
                            $deleteProductArray[] = [
                                'shopwareProductId' => $deleteProductInfo['shopware_id'],
                                'odooProductId' => $deleteProductInfo['odoo_id'],
                            ];
                        }
                    }
                }
                $this->productRepository->upsert([$updateProductData], $context);
                if ($childProducts) {
                    foreach ($childProducts as $childProduct) {
                        $childProductMappingData[] =
                            [
                                'odooTemplateId' => $childProduct['customFields']['odoo_product_id'],
                                'shopwareVariantId' => $childProduct['id']
                            ];
                    }
                }
                $productMappingData = [
                    [
                        'odooTemplateId' => $odooProductId,
                        'shopwareMainProductId' => $updateProductData['id'],
                        'productCategoryIds' => $shopwareCategoryId,
                        'taxData' => $newGeneratedTaxData,
                        'variantProductId' => $childProductMappingData,
                        'deleteProductData' => $deleteProductArray,
                    ]
                ];
            } else {
                $productMappingData = [
                    'odooTemplateId' => $odooProductId,
                    'shopwareMainProductId' => $shopwareProductId,
                    'message' => 'Product not found'
                ];
            }
        }
        return $productMappingData;
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
//                                  dump($propertyDataSubArray);
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

 

    public function getPropertyOptionData($name, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        return $this->optionRepository->search($criteria, $context)->first();
    }

    public function getChildrenData($childProperties, $childData, $taxDataParent, $context): array
    {
        $childProductDataArray = [];
        $childProductName = $childProductDescription = $childProductActive = null;
        foreach ($childProperties as $childProperty) {
            $childProductData = $this->getProductId($childData, $context);
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
            if (! $taxData) {
                $taxData = $this->taxCreate->taxDataGenerate($oddTaxData[0], $context);
                if ($taxData['type'] === 'Success' && $taxData['responseCode'] === 200) {
                    $taxId = $taxData['taxData']['shopwareId'];
                    $shopwarePrice = $childProductData->getPrice()->first();
                    $updateNetPrice = $shopwarePrice->getNet();
                    $updateGrossPrice = $updateNetPrice * $oddTaxData[0]['amount'] / 100 + $updateNetPrice;
                }
            } else {
                $taxId = $taxData->getId();
                $shopwarePrice = $childProductData->getPrice()->first();
                $updateNetPrice = $shopwarePrice->getNet();
                $updateGrossPrice = $updateNetPrice * $taxData->getTaxRate() / 100 + $updateNetPrice;
            }
        } else {
            $taxData = $taxDataParent;
        }
        if (array_key_exists('name', $childData)) {
            $childProductName = $childData['name'];
        }

        if (array_key_exists('description', $childData) && $childData['description'] !== 'False') {
            $childProductDescription = $childData['description'];
        }
        if (array_key_exists('active', $childData)) {
            $childProductActive = $childData['active'];
        }
        // price calculation
        if (array_key_exists('sales_price', $childData)) {
            $odooPrice = $childData['sales_price'];
            $netPrice = $odooPrice;
            $grossPrice = $odooPrice * $taxData->getTaxRate() / 100 + $odooPrice;
        }
        $childProductNumber = $childData['default_code'];
        if (isset($updateNetPrice) && isset($updateGrossPrice)) {
            $grossPrice = $updateGrossPrice;
            $netPrice = $updateNetPrice;
        }

        return [
            'id' => $childProductData ? $childProductData->getId() : Uuid::randomHex(),
            'productNumber' => $childProductNumber,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => $grossPrice, 'net' => $netPrice, 'linked' => false]],
            'stock' => 0,
            'options' => $childProductDataArray,
            'customFields' => ['odoo_product_id' => $childData['id']],
            'name' => $childProductName,
            'active' => boolval($childProductActive),
            'description' => $childProductDescription,
        ];
    }

    public function getPropertyData($propertyName, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $propertyName));
        return $this->propertyRepository->search($criteria, $context)->first();
    }

  

    public function getProductId($childOdooProductId, $context): ?Entity
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addAssociation('children');
        $criteria->addAssociation('price');
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $childOdooProductId['id']));

        if ($childOdooProductId['shopware_product_id']) {
            $criteria->addFilter(new EqualsFilter('id', $childOdooProductId['shopware_product_id']));
        }
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function getProductDataById($deleteProductInfo, $context): ?Entity
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addFilter(new EqualsFilter('id', $deleteProductInfo['shopware_id']));
        $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $deleteProductInfo['odoo_id']));
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function mediaUpload($imageUrlArray, $shopwareProductDataArray, $context): array
    {
        $mediaIds = [];
        if (! $shopwareProductDataArray) {
            $productId = Uuid::randomHex();
        } else {
            $productId = $shopwareProductDataArray->getId();
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

            if (! $searchMedia) {
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

    public function createImageData($updatedTemplateSeperatedInfo, $context)
    {
        $responseProductData = [];
        if (array_key_exists('shopware_product_id', $updatedTemplateSeperatedInfo)) {
            $shopwareProductId = $updatedTemplateSeperatedInfo['shopware_product_id'];
        } else {
            $shopwareProductId = $updatedTemplateSeperatedInfo['shopware_product_tmpl_id'];
        }
        if (array_key_exists('image_url', $updatedTemplateSeperatedInfo)) {
            $this->removeProductMedia($shopwareProductId, $context);
            $odooProductId = $updatedTemplateSeperatedInfo['odoo_id'];
            $shopwareProductDataArray = $this->findProductDataById($shopwareProductId, $odooProductId, $context);
            if ($shopwareProductDataArray) {
                $imageUrlArray = $updatedTemplateSeperatedInfo['image_url'];
                $mediaIds = $this->mediaUpload($imageUrlArray, $shopwareProductDataArray, $context);
                $coverImage = null;
                foreach ($mediaIds as $mediaId) {
                    $coverImage = $mediaId['mediaId'];
                }
                $data = [
                    'id' => $shopwareProductDataArray->getId(),
                    'media' => $mediaIds,
                    'coverId' => $coverImage,
                ];
                $dataUpdate = $this->productRepository->update([$data], $context);
                if (! $dataUpdate->getErrors()) {
                    $responseProductData[] = [
                        'odooTemplateId' => $odooProductId,
                        'shopwareMainProductId' => $shopwareProductDataArray->getId(),
                    ];
                }
            } else {
                $responseProductData = [
                    'type' => 'Error',
                    'responseCode' => 400,
                    'message' => 'Product is not found.'
                ];
            }
        }
        return $responseProductData;
    }
    public function removeProductMedia($shopwareProductId, $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $shopwareProductId));
        $productMediaObjects = $this->productMediaRepository->search($criteria, $context);
        foreach ($productMediaObjects as $productMediaObject) {
            $this->productMediaRepository->delete([['id' => $productMediaObject->getId()]], $context);
        }
        return $shopwareProductId;
    }
    
    public function removeProductFromSalesChannel($shopwareProductDataArray, $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $shopwareProductDataArray->getId()));
        $productMediaObjects = $this->productVisibilityRepository->search($criteria, $context);
        foreach ($productMediaObjects as $productMediaObject) {
            $this->productVisibilityRepository->delete([['id' => $productMediaObject->getId()]], $context);
        }
    }
    public function getManufacturerData($manufacturerId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_manufacturer_id', $manufacturerId));
        return $this->manufacturerRepository->search($criteria, $context)->first();
    }
}


