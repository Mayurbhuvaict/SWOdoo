<?php

declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: ProductManufacturerSyncTask::class)]
class ProductManufacturerSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/product.brand';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $productManufacturerRepository,
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
            $productManufacturerData = $this->fetchManufacturerData($context);
            if ($productManufacturerData) {
                foreach ($productManufacturerData as $productManufacturer) {
                    $productManufacturerId = $productManufacturer->getId();
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $productManufacturer);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $productManufacturerToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $productManufacturerData = $this->buildProductManufacturerData($apiItem, $productManufacturerId);
                                if ($productManufacturerData) {
                                    $productManufacturerToUpsert[] = $productManufacturerData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $productManufacturerData = $this->buildproductManufacturerErrorData($apiItem);
                                if ($productManufacturerData) {
                                    $productManufacturerToUpsert[] = $productManufacturerData;
                                }
                            }
                        }
                    }
                    if (! $productManufacturerToUpsert) {
                        // if (!empty($productManufacturerToUpsert)) {
                        try {
                            $this->productManufacturerRepository->upsert($productManufacturerToUpsert, $context);
                        } catch (\Exception $e) {
                            $this->logger->error('Error in Manufacturer Sync task: ' . $e->getMessage(), [
                                'exception' => $e,
                                'data' => $productManufacturerToUpsert,
                                'apiResponse' => $apiData,
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function fetchManufacturerData($context)
    {
        $manufacturerDataSend = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('media');
        // $criteria->addAssociation('product');
        $productManufacturerData = $this->productManufacturerRepository->search($criteria, $context)->getElements();
        if ($productManufacturerData) {
            foreach ($productManufacturerData as $productManufacturer) {
                $customFields = $productManufacturer->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_manufacturer_id', $customFields)) {
                        if ($customFields['odoo_manufacturer_id'] === null || $customFields['odoo_manufacturer_id'] === 0) {
                            $manufacturerDataSend[] = $productManufacturer;
                        }
                    } elseif (array_key_exists('odoo_manufacturer_error', $customFields) && $customFields['odoo_manufacturer_error'] === null) {
                        $manufacturerDataSend[] = $productManufacturer;
                    }
                } else {
                    $manufacturerDataSend[] = $productManufacturer;
                }
            }
        } 
        return $manufacturerDataSend;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $productManufacturer): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $productManufacturer,
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

    public function buildProductManufacturerData($apiItem, $productManufacturerId): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_manufacturer_id'])) {
            return [
                'id' => $productManufacturerId,
                'customFields' => [
                    'odoo_manufacturer_id' => $apiItem['odoo_manufacturer_id'],
                    'odoo_manufacturer_error' => null,
                    'shopware_product_brand_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildProductManufacturerErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'shopware_product_brand_error' => $apiItem['odoo_shopware_error'],
                    'odoo_manufacturer_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
