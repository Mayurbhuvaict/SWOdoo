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

class CountryStateSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.state';
    private const DELETEMODULE = '/delete/shopware.state';
    private static $isProcessingCountryStateEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $countryStateRepository,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CountryEvents::COUNTRY_STATE_WRITTEN_EVENT => 'onCountryStateWritten',
            CountryEvents::COUNTRY_STATE_DELETED_EVENT => 'onCountryStateDelete',
        ];
    }

    public function onCountryStateWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingCountryStateEvent) {
                return;
            }
            self::$isProcessingCountryStateEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $countryStateId = $writeResult->getPrimaryKey();
                    if ($countryStateId) {
                        $countryStateDataArray = $this->findCountryStateData($countryStateId, $event);
                        if ($countryStateDataArray) {
                            $countryStateDataArray->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
//                            dd($countryStateDataArray);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $countryStateDataArray);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $countryStateToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $countryStateData = $this->buildCountryStateMethodData($apiItem);
                                        if ($countryStateData) {
                                            $countryStateToUpsert[] = $countryStateData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $countryStateData = $this->buildCountryStateErrorData($apiItem);
                                        if ($countryStateData) {
                                            $countryStateToUpsert[] = $countryStateData;
                                        }
                                    }
                                }
                                if (! $countryStateToUpsert) {
                                    // if (! empty($countryStateToUpsert)) {
                                    $this->countryStateRepository->upsert($countryStateToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCountryStateEvent = false;
            }
        }
    }

    public function findCountryStateData($countryStateId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('translations.language');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('country');
        $criteria->addAssociation('country.states');
        $criteria->addAssociation('country.states.translations');
        $criteria->addAssociation('country.translations');
        $criteria->addAssociation('country.translations.language');
        $criteria->addAssociation('countryStates');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('taxRules');
        $criteria->addAssociation('currencyCountryRoundings');
        $criteria->addFilter(new EqualsFilter('id', $countryStateId));
        return $this->countryStateRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $countryStateDataArray): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $countryStateDataArray,
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

    public function buildCountryStateMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_country_state_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_state_id' => $apiItem['odoo_country_state_id'],
                    'odoo_country_state_error' => null,
                    'odoo_country_state_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCountryStateErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_state_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onCountryStateDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingCountryStateEvent) {
                return;
            }
            self::$isProcessingCountryStateEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $countryStateId = $writeResult->getPrimaryKey();
                    if ($countryStateId) {
                        $deleteCountryStateData = [
                            'shopwareId' => $countryStateId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteCountryStateData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $countryStateData = $this->buildCountryStateErrorData($apiItem);
                                    if ($countryStateData) {
                                        $this->countryStateRepository->upsert([$countryStateData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCountryStateEvent = false;
            }
        }
    }
}
