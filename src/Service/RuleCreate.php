<?php

namespace ICTECHOdooShopwareConnector\Service;

use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class RuleCreate
{
    public function __construct(
        private readonly EntityRepository $ruleRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $productPriceRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $ruleConditionRepository
    ) {
    }

    public function getRuleData($ruleType, $ruleDataArray, $context): array
    {
        return $this->createRule($ruleType, $ruleDataArray, $context);
    }

    public function createRule($ruleType, $ruleDataArray, $context): array
    {
        $responseData = $assignRuleProductPricesArray = $basicRuleIdArray = $ruleResponseArray = [];
        if ($ruleType === 'basic') {
            foreach ($ruleDataArray as $ruleData) {
                $odooRuleId = $ruleData['pricelist']['id'];
                $odooMainRuleId = $ruleData['id'];
                $shopwareProductTmplId = $shopwareProductId = null;
                $shopwareRuleData = $this->getShopwareRuleData($ruleType, $odooMainRuleId, $context);
                if ($shopwareRuleData) {
                    $conditionRuleIds = $shopwareRuleData->getConditions()->getElements();
                    foreach ($conditionRuleIds as $conditionRuleId) {
                        $this->ruleConditionRepository->delete([['id' => $conditionRuleId->getId()]], $context);
                    }
                }
                $data = [
                    'id' => $shopwareRuleData ? $shopwareRuleData->getId() : Uuid::randomHex(),
                    'name' => $ruleData['pricelist']['name'] . ' - ' . $ruleData['id'],
                    'priority' => 100,
                    'customFields' => [
                        'odoo_rule_type' => $ruleType,
                        'odoo_rule_pricelist_id' => $odooMainRuleId,
                    ],
                ];
                $salesChannelArray = $this->pluginConfig->getDefaultSalesChannelId()[0];
                if ($salesChannelArray) {
                    $data['conditions'] = [
                        [
                            'type' => 'orContainer',
                            'children' => [
                                [
                                    'type' => 'andContainer',
                                    'children' => [
                                        [
                                            'type' => 'salesChannel',
                                            'value' => [
                                                'operator' => '=',
                                                'salesChannelIds' => [$salesChannelArray],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                $this->ruleRepository->upsert([$data], $context);
                $basicRuleIdArray[] = [
                    'odooRuleId' => $odooMainRuleId,
                    'shopwareRuleId' => $data['id'],
                ];
                if (array_key_exists('product_template_id', $ruleData)) {
                    $product = $ruleData['product_template_id'];
                    $odooProductId = $product['id'];
                    $shopwareProductTmplId = $product['shopware_product_tmpl_id'];
                    $assignRuleProduct = $this->getProductForRules($ruleData, $shopwareProductTmplId, $odooProductId, $shopwareProductId, $odooRuleId, $odooMainRuleId, $ruleType, $context);
                    if ($assignRuleProduct) {
                        $this->productPriceRepository->upsert($assignRuleProduct, $context);
                    }
                }
                if (array_key_exists('product_id', $ruleData) && $ruleData['product_id']) {
                    $product = $ruleData['product_id'];
                    $odooProductId = $product['id'];
                    $shopwareProductId = $product['shopware_product_id'];
                    $assignRuleProduct = $this->getProductForRules($ruleData, $shopwareProductTmplId, $odooProductId, $shopwareProductId, $odooRuleId, $odooMainRuleId, $ruleType, $context);
                    if ($assignRuleProduct) {
                        $this->productPriceRepository->upsert($assignRuleProduct, $context);
                    }
                }
                $responseArray = [
                    'type' => 'Success',
                    'responseCode' => 200,
                    'ruleData' => $basicRuleIdArray,
                ];
            }
            return $responseArray;
        }

        if ($ruleType === 'advanced') {
            foreach ($ruleDataArray as $ruleData) {
                $computation = $ruleData['computation']['compute_price'];
                $computationKey = key($computation);
                $applyOn = $ruleData['apply_on']['applied_on'];
                $applyOnKeyData = key($applyOn);
                $applyOnKeyName = $ruleData['apply_on']['applied_on'][$applyOnKeyData];
                $odooRuleId = $ruleData['id'];
                $ruleName = $computationKey . ' ' . $applyOnKeyName . '-' . $odooRuleId;
                $shopwareRuleData = $this->getShopwareRuleData($ruleType, $odooRuleId, $context);
                if ($shopwareRuleData) {
                    $conditionRuleIds = $shopwareRuleData->getConditions()->getElements();
                    foreach ($conditionRuleIds as $conditionRuleId) {
                        $this->ruleConditionRepository->delete([['id' => $conditionRuleId->getId()]], $context);
                    }
                }
                $data = [
                    'id' => $shopwareRuleData ? $shopwareRuleData->getId() : Uuid::randomHex(),
                    'name' => $ruleName,
                    'priority' => 100,
                    'customFields' => [
                        'odoo_rule_type' => $ruleType,
                        'odoo_rule_pricelist_id' => $odooRuleId,
                    ],
                ];
                $salesChannelArray = $this->pluginConfig->getDefaultSalesChannelId()[0];
                if ($salesChannelArray) {
                    $data['conditions'] = [
                        [
                            'type' => 'orContainer',
                            'children' => [
                                [
                                    'type' => 'andContainer',
                                    'children' => [
                                        [
                                            'type' => 'salesChannel',
                                            'value' => [
                                                'operator' => '=',
                                                'salesChannelIds' => [$salesChannelArray],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                $ruleResponseArray[] = [
                    'odooAdvanceRuleId' => $odooRuleId,
                    'shopwareRuleId' => $data['id'],
                ];
                $this->ruleRepository->upsert([$data], $context);
                // apply rule in product
                $this->applyAdvanceRuleProduct($computationKey, $applyOnKeyData, $odooRuleId, $ruleData, $ruleType, $context);
                $responseData = [
                    'type' => 'Success',
                    'responseCode' => 200,
                    'odooAdvanceRuleData' => $ruleResponseArray,
                ];
            }
        }
        return $responseData;
    }

    public function getShopwareRuleData($ruleType, $odooMainRuleId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('conditions');
        $criteria->addFilter(new EqualsFilter('customFields.odoo_rule_type', $ruleType));
        $criteria->addFilter(new EqualsFilter('customFields.odoo_rule_pricelist_id', $odooMainRuleId));
        return $this->ruleRepository->search($criteria, $context)->first();
    }

    public function getProductForRules($ruleData, $shopwareProductTmplId, $odooProductId, $shopwareProductId, $odooRuleId, $odooMainRuleId, $ruleType, $context): array
    {
        $rule = $this->getShopwareRule($odooMainRuleId, $context);
        $productData = $this->getProductRuleData($odooProductId, $shopwareProductTmplId, $shopwareProductId, $context);
        $advancePriceArray = [];
        if ($productData && $rule) {
            $productPriceData = $this->fetchShopwareRuleForProduct($rule, $productData, $ruleType, $context);
            if (array_key_exists('from_write', $ruleData) && $productPriceData) {
                foreach ($productPriceData as $productPrice) {
                    $this->productPriceRepository->delete([['id' => $productPrice->getId()]], $context);
                }
            }
            $productPrice = $productData->getPrice()->first();
            $grossPrice = $productPrice->getGross();
            $netPrice = $productPrice->getNet();
            $advancePriceArray = [
                [
                    'productId' => $productData->getId(),
                    'ruleId' => $rule->getId(),
                    'price' => [
                        [
                            'net' => $netPrice ?? $ruleData['fixed_price'],
                            'gross' => $grossPrice ?? $ruleData['fixed_price'],
                            'linked' => false,
                            'currencyId' => Defaults::CURRENCY,
                        ]
                    ],
                    'quantityStart' => 1,
                    'quantityEnd' => (int) $ruleData['min_quantity'] > 0 ? (int) $ruleData['min_quantity'] - 1 : 1,
                ],
                [
                    'productId' => $productData->getId(),
                    'ruleId' => $rule->getId(),
                    'price' => [
                        [
                            'net' => $ruleData['fixed_price'],
                            'gross' => $ruleData['fixed_price'],
                            'linked' => false,
                            'currencyId' => Defaults::CURRENCY,
                        ]
                    ],
                    'quantityStart' => (int) $ruleData['min_quantity'] ?? 2,
                    'quantityEnd' => null,
                ]
            ];
        }
        return $advancePriceArray;
    }

    public function getShopwareRule($odooMainRuleId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_rule_pricelist_id', $odooMainRuleId));
        return $this->ruleRepository->search($criteria, $context)->first();
    }

    public function getProductRuleData($odooProductId, $shopwareProductTmplId, $shopwareProductId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('child');
        $criteria->addAssociation('tax');
        $context->setConsiderInheritance(true);
        if ($odooProductId) {
            $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $odooProductId));
        }
        if ($shopwareProductId) {
            $criteria->addFilter(new EqualsFilter('id', $shopwareProductId));
        }
        if ($shopwareProductTmplId) {
            $criteria->addFilter(new EqualsFilter('id', $shopwareProductTmplId));
        }
        return $this->productRepository->search($criteria, $context)->first();
    }

    public function fetchShopwareRuleForProduct($ruleId, $productData, $ruleType, $context): array
    {
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addAssociation('rules');
        $criteria->addFilter(new EqualsFilter('productId', $productData->getId()));
        $criteria->addFilter(new EqualsFilter('ruleId', $ruleId->getId()));
        return $this->productPriceRepository->search($criteria, $context)->getElements();
    }

    public function applyAdvanceRuleProduct($computationKey, $applyOnKeyData, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $getAppliedRuleData = [];
        $criteria = new Criteria();
        $context->setConsiderInheritance(true);
        $criteria->addAssociation('tax');
        if ($applyOnKeyData === '3_global') {
            $criteria->addFilter(new NotFilter(
                MultiFilter::CONNECTION_AND,
                [new EqualsFilter('customFields.odoo_product_id', null)]
            ));

            $allProducts = $this->productRepository->search($criteria, $context)->getElements();
            if ($computationKey === 'percentage') {
                $allProducts = $this->productRepository->search($criteria, $context)->getElements();
                $getAppliedRuleData = $this->applyAdvanceRulePercentageProductAllProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
            } else {
                $getAppliedRuleData = $this->applyAdvanceRuleProductAllProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
            }
            if (array_key_exists('product_category', $ruleData['apply_on'])) {
                $criteria->addFilter(new EqualsFilter('categories.customFields.odoo_category_id', $ruleData['apply_on']['product_category']['categ_id']));
                $allProducts = $this->productRepository->search($criteria, $context)->getElements();
                $getAppliedRuleData = $this->applyAdvanceRuleProductCategory($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
            }
        }
        if (array_key_exists('product_category', $ruleData['apply_on'])) {
            $criteria->addFilter(new EqualsFilter('categories.customFields.odoo_category_id', $ruleData['apply_on']['product_category']['categ_id']));
            $allProducts = $this->productRepository->search($criteria, $context)->getElements();
            $getAppliedRuleData = $this->applyAdvanceRuleProductCategory($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
        }
        if ($applyOnKeyData === '1_product') {
            if (array_key_exists('product_template', $ruleData['apply_on'])) {
                $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $ruleData['apply_on']['product_template']['product_tmpl_id']));
                $criteria->addFilter(new EqualsFilter('id', $ruleData['apply_on']['product_template']['shopware_product_tmpl_id']));
            }
            if (array_key_exists('product_variant', $ruleData['apply_on'])) {
                $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $ruleData['apply_on']['product_variant']['shopware_product_id']));
                $criteria->addFilter(new EqualsFilter('id', $ruleData['apply_on']['product_variant']['shopware_product_id']));
            }
            $allProducts = $this->productRepository->search($criteria, $context)->getElements();
            $getAppliedRuleData = $this->applyAdvanceRuleForOneProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
        }
        if ($applyOnKeyData === '0_product_variant') {
            $criteria->addAssociation('child');
            $criteria->addFilter(new EqualsFilter('customFields.odoo_product_id', $ruleData['apply_on']['product_variant']['product_id']));
            if ($ruleData['apply_on']['product_variant']['shopware_product_id']) {
                $criteria->addFilter(new EqualsFilter('id', $ruleData['apply_on']['product_variant']['shopware_product_id']));
            }
            $allProducts = $this->productRepository->search($criteria, $context)->getElements();
            $getAppliedRuleData = $this->applyAdvanceRuleForVariantProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context);
        }
        return $getAppliedRuleData;
    }

    public function applyAdvanceRulePercentageProductAllProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $productsData = [];
        foreach ($allProducts as $productData) {
            $productCustomFieldData = $productData->getCustomFields();
            if ($productCustomFieldData && array_key_exists('odoo_product_id', $productCustomFieldData)) {
                $netPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getNet();
                $grossPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getGross();
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, null, $context);
            }
        }
        return $productsData;
    }

    public function setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context): array
    {
        $productsData = [];
        $rule = $this->getShopwareRule($odooRuleId, $context);
        $productPriceData = $this->fetchShopwareRuleForProduct($rule, $productData, $ruleType, $context);
        if (array_key_exists('from_write', $ruleData) && $productPriceData) {
            foreach ($productPriceData as $productPrice) {
                $this->productPriceRepository->delete([['id' => $productPrice->getId()]], $context);
            }
        }
        $productPrice = $productData->getPrice()->first();
        $grossPrice = $productPrice->getGross();
        $netPrice = $productPrice->getNet();
        $taxRate = $productData->getTax()->getTaxRate();
        if (array_key_exists('fixed_price', $ruleData['computation'])) {
            $newNetPrice = $ruleData['computation']['fixed_price'];
            $newGrossPrice = $newNetPrice * (100 + $taxRate) / 100;
        } else {
            $percentagePrice = $ruleData['computation']['percent_price'];
            $productShopwarePrice = $productData->getPrice(Defaults::CURRENCY)->first();
            $newNetPrice = $productShopwarePrice->getNet() - $productShopwarePrice->getNet() * $percentagePrice / 100;
            $newGrossPrice = $newNetPrice * $taxRate / 100 + $newNetPrice;
        }
        if ($ruleData['min_quantity'] === '' || $ruleData['min_quantity'] === 1.0) {
            $advancePriceData = [
                [
                    'productId' => $productData->getId(),
                    'ruleId' => $rule->getId(),
                    'price' => [
                        [
                            'net' => $newNetPrice ?? $netPrice,
                            'gross' => $newGrossPrice ?? $grossPrice,
                            'linked' => false,
                            'currencyId' => Defaults::CURRENCY,
                        ]
                    ],
                    'quantityStart' => 1,
                    'quantityEnd' => null
                ]
            ];
        } else {
            $advancePriceData = [
                [
                    'productId' => $productData->getId(),
                    'ruleId' => $rule->getId(),
                    'price' => [
                        [
                            'net' => $netPrice ?? 0,
                            'gross' => $grossPrice ?? 0,
                            'linked' => false,
                            'currencyId' => Defaults::CURRENCY,
                        ]
                    ],
                    'quantityStart' => 1,
                    'quantityEnd' => (int) $ruleData['min_quantity'] > 0 ? (int) $ruleData['min_quantity'] - 1 : 1,
                ],
                [
                    'productId' => $productData->getId(),
                    'ruleId' => $rule->getId(),
                    'price' => [
                        [
                            'net' => $newNetPrice,
                            'gross' => $newGrossPrice,
                            'linked' => false,
                            'currencyId' => Defaults::CURRENCY,
                        ]
                    ],
                    'quantityStart' => (int) $ruleData['min_quantity'] > 0 ? (int) $ruleData['min_quantity'] : 1,
                    'quantityEnd' => null,
                ]
            ];
        }
        $this->productPriceRepository->upsert($advancePriceData, $context);
        $productsData[] = [
            'odooId' => $productData->getCustomFields()['odoo_product_id'],
            'productId' => $productData->getId(),
        ];
        return $productsData;
    }

    public function applyAdvanceRuleProductAllProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $productsData = [];
        foreach ($allProducts as $productData) {
            $productCustomFieldData = $productData->getCustomFields();
            if ($productCustomFieldData && array_key_exists('odoo_product_id', $productCustomFieldData)) {
                $netPrice = $ruleData['computation']['fixed_price'];
                $grossPrice = $ruleData['computation']['fixed_price'];
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, null, $context);
            }
        }
        return $productsData;
    }

    public function applyAdvanceRuleProductCategory($allProducts, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $productsData = [];
        foreach ($allProducts as $productData) {
            $discount = null;
            if (array_key_exists('fixed_price', $ruleData['computation'])) {
                $netPrice = $ruleData['computation']['fixed_price'];
                $grossPrice = $ruleData['computation']['fixed_price'];
                $discount = 'fixed_price';
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }

            if (array_key_exists('percent_price', $ruleData['computation'])) {
                $percentage = $ruleData['computation']['percent_price'];
                $discount = 'percent_price';
            }
            if ($discount === 'percent_price') {
                $shopwareNetPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getNet();
                $shopwareGrossPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getGross();
                $grossPrice = $shopwareGrossPrice - $shopwareGrossPrice * $percentage / 100;
                $netPrice = $shopwareNetPrice - $shopwareNetPrice * $percentage / 100;
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }
        }
        return $productsData;
    }

    public function applyAdvanceRuleForOneProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $productsData = [];
        $discount = null;
        foreach ($allProducts as $productData) {
            if (array_key_exists('fixed_price', $ruleData['computation'])) {
                $netPrice = $ruleData['computation']['fixed_price'];
                $grossPrice = $ruleData['computation']['fixed_price'];
                $discount = 'fixed_price';
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }
            if (array_key_exists('percent_price', $ruleData['computation'])) {
                $percentage = $ruleData['computation']['percent_price'];
                $discount = 'percent_price';
            }
            if ($discount === 'percent_price') {
                $shopwareNetPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getNet();
                $shopwareGrossPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getGross();
                $grossPrice = $shopwareGrossPrice - $shopwareGrossPrice * $percentage / 100;
                $netPrice = $shopwareNetPrice - $shopwareNetPrice * $percentage / 100;
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }
        }
        return $productsData;
    }

    public function applyAdvanceRuleForVariantProduct($allProducts, $odooRuleId, $ruleData, $ruleType, $context): array
    {
        $productsData = [];
        $discount = null;
        foreach ($allProducts as $productData) {
            if (array_key_exists('fixed_price', $ruleData['computation'])) {
                $netPrice = $ruleData['computation']['fixed_price'];
                $grossPrice = $ruleData['computation']['fixed_price'];
                $discount = 'fixed_price';
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }
            if (array_key_exists('percent_price', $ruleData['computation'])) {
                $percentage = $ruleData['computation']['percent_price'];
                $discount = 'percent_price';
            }
            if ($discount === 'percent_price') {
                $shopwareNetPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getNet();
                $shopwareGrossPrice = $productData->getPrice(Defaults::CURRENCY)->first()->getGross();
                $grossPrice = $shopwareGrossPrice - $shopwareGrossPrice * $percentage / 100;
                $netPrice = $shopwareNetPrice - $shopwareNetPrice * $percentage / 100;
                $productsData = $this->setAdvancedRule($odooRuleId, $productData, $ruleData, $netPrice, $grossPrice, $ruleType, $discount, $context);
            }
        }
        return $productsData;
    }
}
