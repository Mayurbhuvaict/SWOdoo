<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Currency\CurrencyEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CurrencySubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.currency';
    private const DELETEMODULE = '/delete/shopware.currency';
    private static $isProcessingCurrencyEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $currencyRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CurrencyEvents::CURRENCY_WRITTEN_EVENT => 'onCurrencyWritten',
            CurrencyEvents::CURRENCY_DELETED_EVENT => 'onCurrencyDelete',
        ];
    }

    public function onCurrencyWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCurrencyEvent) {
                return;
            }
            self::$isProcessingCurrencyEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $currencyId = $writeResult->getPrimaryKey();
                    if ($currencyId) {
                        $currency = $this->findCurrencyData($currencyId, $event);
                        if ($currency) {
                            $currency->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $currency);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $currenciesToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $currencyData = $this->buildCurrencyData($apiItem);
                                        if ($currencyData) {
                                            $currenciesToUpsert[] = $currencyData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $currencyData = $this->buildCurrencyErrorData($apiItem);
                                        if ($currencyData) {
                                            $currenciesToUpsert[] = $currencyData;
                                        }
                                    }
                                }
                                if (!empty($currenciesToUpsert)) {
                                    try {
                                        $this->currencyRepository->upsert($currenciesToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in currency sync real-time', [
                                            'exception' => $e,
                                            'data' => $currenciesToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCurrencyEvent = false;
            }
        }
    }

    public function findCurrencyData($currencyId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('salesChannels');
        $criteria->addFilter(new EqualsFilter('id', $currencyId));
        return $this->currencyRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $currency): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $currency,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error('API request failed', [
                'exception' => $e,
                'apiUrl' => $apiUrl,
                'odooToken' => $odooToken,
            ]);
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildCurrencyData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_currency_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_currency_id' => $apiItem['odoo_currency_id'],
                    'odoo_currency_error' => null,
                    'odoo_currency_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCurrencyErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_currency_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onCurrencyDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCurrencyEvent) {
                return;
            }
            self::$isProcessingCurrencyEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $currencyId = $writeResult->getPrimaryKey();
                    if ($currencyId) {
                        $deleteCurrencyData = [
                            'shopwareId' => $currencyId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteCurrencyData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $currencyData = $this->buildCurrencyErrorData($apiItem);
                                    if ($currencyData) {
                                        try {
                                            $this->currencyRepository->upsert([$currencyData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in currency delete', [
                                                'exception' => $e,
                                                'data' => $deleteCurrencyData,
                                                'apiResponse' => $apiData,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCurrencyEvent = false;
            }
        }
    }
}
