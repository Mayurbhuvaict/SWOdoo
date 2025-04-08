<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DeliveryTimeSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.delivery.time';
    private const DELETEMODULE = '/delete/shopware.delivery.time';
    private static $isProcessingDeliveryTimeEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'delivery_time.written' => 'onDeliveryTimeWritten',
            'delivery_time.deleted' => 'onDeliveryTimeDelete',
        ];
    }

    public function onDeliveryTimeWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingDeliveryTimeEvent) {
                return;
            }
            self::$isProcessingDeliveryTimeEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $deliveryTimeId = $writeResult->getPrimaryKey();
                    if ($deliveryTimeId) {
                        $deliveryTime = $this->findDeliveryTimeData($deliveryTimeId, $event);
                        if ($deliveryTime) {
                            $userId = $event->getContext()->getSource()->getUserId();
                            $deliveryTime->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deliveryTime);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $deliveryTimeToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $deliveryTimeData = $this->buildDeliveryTimeData($apiItem);
                                        if ($deliveryTimeData) {
                                            $deliveryTimeToUpsert[] = $deliveryTimeData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $deliveryTimeData = $this->buildDeliveryTimeErrorData($apiItem);
                                        if ($deliveryTimeData) {
                                            $deliveryTimeToUpsert[] = $deliveryTimeData;
                                        }
                                    }
                                }
                                if (!empty($deliveryTimeToUpsert)) {
                                    try {
                                        $this->deliveryTimeRepository->upsert($deliveryTimeToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in delivery-time sync real-time', [
                                            'exception' => $e,
                                            'data' => $deliveryTimeToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingDeliveryTimeEvent = false;
            }
        }
    }

    public function findDeliveryTimeData($deliveryTimeId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('id', $deliveryTimeId));
        return $this->deliveryTimeRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $deliveryTime): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $deliveryTime,
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

    private function buildDeliveryTimeData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_delivery_time_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_delivery_time_id' => $apiItem['odoo_delivery_time_id'],
                    'odoo_delivery_time_error' => null,
                    'odoo_delivery_time_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildDeliveryTimeErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_delivery_time_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onDeliveryTimeDelete(EntityDeletedEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingDeliveryTimeEvent) {
                return;
            }
            self::$isProcessingDeliveryTimeEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $deliveryTimeId = $writeResult->getPrimaryKey();
                    if ($deliveryTimeId) {
                        $deleteDeliveryTimeData = [
                            'shopwareId' => $deliveryTimeId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteDeliveryTimeData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $deliveryTimeData = $this->buildDeliveryTimeErrorData($apiItem);
                                    if ($deliveryTimeData) {
                                        try {
                                            $this->deliveryTimeRepository->upsert([$deliveryTimeData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in delivery-time delete', [
                                                'exception' => $e,
                                                'data' => $deleteDeliveryTimeData,
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
                self::$isProcessingDeliveryTimeEvent = false;
            }
        }
    }
}
