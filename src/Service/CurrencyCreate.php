<?php

namespace ICTECHOdooShopwareConnector\Service;

use Exception;
use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class CurrencyCreate
{
    public const END_POINT = '/shop/currency';

    public function __construct(
        private readonly EntityRepository $currencyRepository,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    public function getCurrencyData($currencyDataArray, $context): array
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        if ($odooUrl !== "null") {
            $currencyResponse = [];
            $apiUrl = $odooUrl . self::END_POINT;
            if ($currencyDataArray && $currencyDataArray !== 'getAllCurrencyData') {
                $currencyResponse = $this->insertActiveCurrencyData($currencyDataArray, $context);
            } else {
                $odooCategoryAPIData = $this->getCurrencyApiData($apiUrl);
                if ($odooCategoryAPIData && $odooCategoryAPIData->result->code === 200) {
                    $apiData = $odooCategoryAPIData->result;
                    if ($apiData->success) {
                        $currencyDataArray = $apiData->data;
                        $currencyResponse = $this->insertCurrencyData($currencyDataArray, $context);
                    }
                }
                else {
                    return [
                        'type' => "Error",
                        'responseCode' => 400,
                    ];
                }
            }
            return $currencyResponse;
        } else {
            return [
                'type' => "Error",
                'responseCode' => 400,
            ];
        }
    }

    public function insertActiveCurrencyData($currencyDataArray, $context): array
    {
        $responseDataArray = $responseData = [];
        foreach ($currencyDataArray as $currencyData) {
            $shopwareCurrencyId = $currencyData['shopware_currency_id'];
            $shopwareCurrencyData = $this->checkActivateCurrencyData($currencyData, $shopwareCurrencyId, $context);
            if (array_key_exists('currency_code', $currencyData)) {
                $currencyIsoCode = $currencyData['currency_code'];
            } else {
                $currencyIsoCode = $shopwareCurrencyData->getIsoCode();
            }

            if (array_key_exists('symbol', $currencyData)) {
                $currencySymbol = $currencyData['symbol'];
            } else {
                $currencySymbol = $shopwareCurrencyData->getSymbol();
            }
            if (array_key_exists('full_name', $currencyData)) {
                $currencyName = $currencyData['full_name'];
            } else {
                $currencyName = $shopwareCurrencyData->getName();
            }
            if (array_key_exists('currency_unit_label', $currencyData)) {
                $currencyShortName = $currencyData['currency_unit_label'];
            } else {
                $currencyShortName = $shopwareCurrencyData->getShortName();
            }
            if (array_key_exists('rates', $currencyData) && $currencyData['rates']) {
                $currencyFactor = $currencyData['rates'][0]['company_rate'];
            } else {
                $currencyFactor = $shopwareCurrencyData ? $shopwareCurrencyData->getFactor() : 1;
            }
            if (array_key_exists('decimal_places', $currencyData)) {
                $currencyItemRounding = $currencyData['decimal_places'];
            } else {
                $currencyItemRounding = $shopwareCurrencyData->getItemRounding()->getDecimals();
            }
            if (array_key_exists('decimal_places', $currencyData)) {
                $currencyTotalRounding = $currencyData['decimal_places'];
            } else {
                $currencyTotalRounding = $shopwareCurrencyData->getTotalRounding()->getDecimals();
            }
            if ($currencyData['active']) {
                $data = [
                    'id' => $shopwareCurrencyData ? $shopwareCurrencyData->getId() : Uuid::randomHex(),
                    'name' => $currencyName,
                    'factor' => $currencyFactor,
                    'symbol' => $currencySymbol,
                    'isoCode' => $currencyIsoCode,
                    'shortName' => $currencyShortName,
                    'position' => 1,
                    'itemRounding' => [
                        'decimals' => $currencyItemRounding,
                        'interval' => 1,
                        'roundForNet' => true,
                    ],
                    'totalRounding' => [
                        'decimals' => $currencyTotalRounding,
                        'interval' => 1,
                        'roundForNet' => true,
                    ],
                    'customFields' => ['odoo_currency_id' => $currencyData['id']],
                ];
                try {
                    $this->currencyRepository->upsert([$data], $context);
                } catch (Exception $e) {
                    $responseDataArray = [
                        'type' => 'error',
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ];
                }
                $responseDataArray[] = [
                    'odooId' => $currencyData['id'],
                    'shopwareId' => $data['id']
                ];
            } else {
                $deactivateCategory = $this->deactivateOdooCategory($currencyData, $context);
            }
        }
        if ($responseDataArray) {
            $responseData = [
                'type' => 'Success',
                'responseCode' => 200,
                'currencyData' => $responseDataArray
            ];
        }
        return $responseData ? $responseData : $deactivateCategory;
    }

    public function checkActivateCurrencyData($currencyData, $shopwareCurrencyId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('isoCode', $currencyData['currency_code']));
        if ($shopwareCurrencyId) {
            $criteria->addFilter(new EqualsFilter('id', $shopwareCurrencyId));
        }
        return $this->currencyRepository->search($criteria, $context)->first();
    }

    //get api data
    public function deactivateOdooCategory($currencyData, $context): array
    {
        $currencyDataArray = $responseData = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_currency_id', $currencyData['id']));
        $currencyDataIds = $this->currencyRepository->search($criteria, $context)->getElements();
        if ($currencyDataIds) {
            foreach ($currencyDataIds as $currencyDataId) {
                $this->currencyRepository->delete([['id' => $currencyDataId->getId()]], $context);
                $currencyData = [
                    'odooId' => $currencyDataId->getCustomFields()['odoo_currency_id'],
                    'shopwareId' => $currencyDataId->getId(),
                ];
                $currencyDataArray[] = $currencyData;
            }
            if ($currencyDataArray) {
                $responseData = [
                    'type' => 'Success',
                    'responseCode' => 204,
                    'currencyData' => $currencyDataArray
                ];
            }
        } else {
            $responseData = [
                'type' => 'Error',
                'responseCode' => 404,
                'message' => 'No currency data found'
            ];
        }
        return $responseData;
    }

    public function getCurrencyApiData($apiUrl)
    {
        $apiResponse = $this->client->get(
            $apiUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
            ]
        );
        return json_decode($apiResponse->getBody()->getContents());
    }

    public function insertCurrencyData($currencyDataArray, $context): array
    {
        $responseDataArray = $responseData = [];
        foreach ($currencyDataArray as $currencyData) {
            $shopwareCurrencyData = $this->checkCurrencyData($currencyData, $context);
            if ($currencyData->active) {
                if ($shopwareCurrencyData) {
                    $data = [
                        'id' => $shopwareCurrencyData->getId(),
                        'name' => $shopwareCurrencyData->getName(),
                        'factor' => $shopwareCurrencyData->getFactor(),
                        'symbol' => $shopwareCurrencyData->getSymbol(),
                        'isoCode' => $currencyData->currency_code,
                        'shortName' => $currencyData->currency_unit_label,
                        'position' => 1,
                        'itemRounding' => [
                            'decimals' => intval($currencyData->decimal_places),
                            'interval' => 1,
                            'roundForNet' => true,
                        ],
                        'totalRounding' => [
                            'decimals' => intval($currencyData->decimal_places),
                            'interval' => 1,
                            'roundForNet' => true,
                        ],
                        'customFields' => ['odoo_currency_id' => $currencyData->id],
                    ];
                } else {
                    if ($currencyData->rates) {
                        $currencyRates = $currencyData->rates;
                    }
                    $data = [
                        'id' => Uuid::randomHex(),
                        'name' => $currencyData->full_name,
                        'factor' => $currencyRates ?? 1,
                        'symbol' => $currencyData->symbol,
                        'isoCode' => $currencyData->currency_code,
                        'shortName' => $currencyData->currency_unit_label,
                        'position' => 1,
                        'itemRounding' => [
                            'decimals' => intval($currencyData->decimal_places),
                            'interval' => 1,
                            'roundForNet' => true,
                        ],
                        'totalRounding' => [
                            'decimals' => intval($currencyData->decimal_places),
                            'interval' => 1,
                            'roundForNet' => true,
                        ],
                        'customFields' => ['odoo_currency_id' => $currencyData->id],
                    ];
                }
                try {
                    $this->currencyRepository->upsert([$data], $context);
                } catch (Exception $e) {
                    $responseDataArray = [
                        'type' => 'error',
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ];
                }
                $responseDataArray[] = [
                    'odooId' => $currencyData->id,
                    'shopwareId' => $data['id']
                ];
            } else {
                $deactivateCategory = $this->deactivateCategory($currencyData, $context);
            }
        }

        if ($responseDataArray) {
            return $responseDataArray;
        }
        return $responseData ? $responseData : $deactivateCategory;
    }

    public function checkCurrencyData($currencyData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_currency_id', $currencyData->id));
        return $this->currencyRepository->search($criteria, $context)->first();
    }

    public function deactivateCategory($currencyData, $context): array
    {
        $currencyDataArray = $responseData = [];
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter('customFields.odoo_currency_id', $currencyData->id));

        $currencyDataIds = $this->currencyRepository->search($criteria, $context)->getElements();

        foreach ($currencyDataIds as $currencyDataId) {
            $this->currencyRepository->delete([['id' => $currencyDataId->getId()]], $context);
            $currencyData = [
                'odooId' => $currencyDataId->getCustomFields()['odoo_currency_id'],
                'shopwareId' => $currencyDataId->getId(),
            ];
            $currencyDataArray[] = $currencyData;
        }
        if ($currencyDataArray) {
            $responseData = [
                'type' => 'Success',
                'responseCode' => 204,
                'currencyData' => $currencyDataArray
            ];
        } else {
            $responseData = [
                'type' => 'Success',
                'responseCode' => 204,
                'message' => 'Currency data not found'
            ];
        }
        return $responseData;
    }

    public function getOdooCurrencyData($context): array
    {
        $currencyDataArray = [];
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(
            MultiFilter::CONNECTION_AND,
            [new EqualsFilter('customFields.odoo_currency_id', null)]
        ));
        $currencyData = $this->currencyRepository->search($criteria, $context)->getEntities();
        foreach ($currencyData as $currency) {
            $currencyArray = [
                'odooId' => $currency->getCustomFields()['odoo_currency_id'],
                'shopwareId' => $currency->getId(),
            ];
            $currencyDataArray[] = $currencyArray;
        }
        return $currencyDataArray;
    }
}
