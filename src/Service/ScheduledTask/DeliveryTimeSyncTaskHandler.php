<?php

declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: DeliveryTimeSyncTask::class)]
class DeliveryTimeSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.delivery.time';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->client = new Client();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== 'null' && $odooToken) {
            $deliveryTimeDataArray = $this->fetchDeliveryTime($context);
            if ($deliveryTimeDataArray) {
                foreach ($deliveryTimeDataArray as $deliveryTime) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deliveryTime);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $deliveryTimeToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $deliveryTimeData = $this->buildDeliveryTime($apiItem);
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
                        if (! $deliveryTimeToUpsert) {
                            try {
                                $this->deliveryTimeRepository->upsert($deliveryTimeToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in delivery-time task', [
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
    }

    public function fetchDeliveryTime($context): ?array
    {
        $deliveryData = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('shippingMethods');
        $criteria->addAssociation('products');
        $deliveryTimeDataArray = $this->deliveryTimeRepository->search($criteria, $context)->getElements();
        if ($deliveryTimeDataArray) {
            foreach ($deliveryTimeDataArray as $deliveryTimeData) {
                $customFields = $deliveryTimeData->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_delivery_time_id', $customFields) && $customFields['odoo_delivery_time_id'] === null || $customFields['odoo_delivery_time_id'] === 0 ) {
                        $deliveryData[] = $deliveryTimeData;
                    } elseif (array_key_exists('odoo_delivery_time_error', $customFields) && $customFields['odoo_delivery_time_error'] === null) {  
                        $deliveryData[] = $deliveryTimeData;
                    }
                } else {
                    $deliveryData[] = $deliveryTimeData;
                }
            }
        }
        return $deliveryData;
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

    public function buildDeliveryTime($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_delivery_time_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_delivery_time_id' => $apiItem['odoo_delivery_time_id'],
                    'odoo_delivery_time_error' => null,
                    'odoo_delivery_time_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildDeliveryTimeErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_delivery_time_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
