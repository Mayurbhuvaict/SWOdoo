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
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesChannelSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.sales.channel';
    private const DELETEMODULE = '/delete/shopware.sales.channel';
    private static $isProcessingSalesChannelEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $salesChannelRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelEvents::SALES_CHANNEL_WRITTEN => 'onSalesChannelWritten',
            SalesChannelEvents::SALES_CHANNEL_DELETED => 'onSalesChannelDelete',
        ];
    }

    public function onSalesChannelWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingSalesChannelEvent) {
                return;
            }
            self::$isProcessingSalesChannelEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $salesChannelId = $writeResult->getPrimaryKey();
                    if ($salesChannelId) {
                        $salesChannel = $this->findSalesChannelData($salesChannelId, $event);
                        if ($salesChannel) {
                            $salesChannel->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $salesChannel);
                            $salesChannelToUpsert = [];

                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $salesChannelData = $this->buildSalesChannelData($apiItem);
                                        if ($salesChannelData) {
                                            $salesChannelToUpsert[] = $salesChannelData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $salesChannelData = $this->buildSalesChannelErrorData($apiItem);
                                        if ($salesChannelData) {
                                            $salesChannelToUpsert[] = $salesChannelData;
                                        }
                                    }
                                }
                                if (!empty($salesChannelToUpsert)) {
                                    try {
                                     $this->salesChannelRepository->upsert($salesChannelToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in sales-channel sync task', [
                                            'exception' => $e,
                                            'data' => $salesChannelToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingSalesChannelEvent = false;
            }
        }
    }

    public function findSalesChannelData($salesChannelId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('navigationSalesChannels');
        $criteria->addAssociation('footerSalesChannels');
        $criteria->addAssociation('serviceSalesChannels');
        $criteria->addFilter(new EqualsFilter('id', $salesChannelId));
        return $this->salesChannelRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $salesChannel): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $salesChannel,
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

    private function buildSalesChannelData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_sales_channel_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_sales_channel_id' => $apiItem['odoo_sales_channel_id'],
                    'odoo_sales_channel_error' => null,
                    'odoo_sales_channel_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildSalesChannelErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_sales_channel_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onSalesChannelDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingSalesChannelEvent) {
                return;
            }
            self::$isProcessingSalesChannelEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $salesChannelId = $writeResult->getPrimaryKey();
                    if ($salesChannelId) {
                        $deleteSalesChannelData = [
                            'shopwareId' => $salesChannelId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteSalesChannelData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $salesChannelData = $this->buildSalesChannelErrorData($apiItem);
                                    if ($salesChannelData) {
                                        try {
                                            $this->salesChannelRepository->upsert([$salesChannelData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in sales-channel delete', [
                                                'exception' => $e,
                                                'data' => $salesChannelData,
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
                self::$isProcessingSalesChannelEvent = false;
            }
        }
    }
}
