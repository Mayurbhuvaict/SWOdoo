<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CountrySubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.country';
    private const DELETEMODULE = '/delete/shopware.country';
    private static $isProcessingCountryEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $countryRepository,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CountryEvents::COUNTRY_WRITTEN_EVENT => 'onCountryWritten',
            CountryEvents::COUNTRY_DELETED_EVENT => 'onCountryDelete',
        ];
    }

    public function onCountryWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingCountryEvent) {
                return;
            }
            self::$isProcessingCountryEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $countryId = $writeResult->getPrimaryKey();
                    if ($countryId) {
                        $countryDataArray = $this->findCountryData($countryId, $event);
                        if ($countryDataArray) {
                            $countryDataArray->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
//                            dd($countryDataArray);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $countryDataArray);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $countryToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $countryData = $this->buildCountryMethodData($apiItem);
                                        if ($countryData) {
                                            $countryToUpsert[] = $countryData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $countryData = $this->buildCountryErrorData($apiItem);
                                        if ($countryData) {
                                            $countryToUpsert[] = $countryData;
                                        }
                                    }
                                }
                                if (! $countryToUpsert) {
                                    // if (! empty($countryToUpsert)) {
                                    $this->countryRepository->upsert($countryToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCountryEvent = false;
            }
        }
    }

    public function findCountryData($countryId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('states');
        $criteria->addAssociation('states.country');
        $criteria->addAssociation('states.translations');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('countryStates');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('taxRules');
        $criteria->addAssociation('currencyCountryRoundings');
        $criteria->addFilter(new EqualsFilter('id', $countryId));
        return $this->countryRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $countryDataArray): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $countryDataArray,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function buildCountryMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_country_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_id' => $apiItem['odoo_country_id'],
                    'odoo_country_error' => null,
                    'odoo_country_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCountryErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onCountryDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingCountryEvent) {
                return;
            }
            self::$isProcessingCountryEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $countryId = $writeResult->getPrimaryKey();
                    if ($countryId) {
                        $deleteCountryData = [
                            'shopwareId' => $countryId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteCountryData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $countryData = $this->buildCountryErrorData($apiItem);
                                    if ($countryData) {
                                        $this->countryRepository->upsert([$countryData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCountryEvent = false;
            }
        }
    }
}
