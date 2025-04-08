<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductManufacturerSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/product.brand';
    private const DELETEMODULE = '/delete/product.brand';
    private static $isProcessingProductManufacturerEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $productManufacturerRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT => 'onProductManufacturerWritten',
            ProductEvents::PRODUCT_MANUFACTURER_DELETED_EVENT => 'onProductManufacturerDelete'
        ];
    }

    public function onProductManufacturerWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductManufacturerEvent) {
                return;
            }
            self::$isProcessingProductManufacturerEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productManufacturerId = $writeResult->getPrimaryKey();
                    if ($productManufacturerId) {
                        $productManufacturer = $this->findManufacturerData($productManufacturerId, $event);
                        if ($productManufacturer) {
                            $userId = $event->getContext()->getSource()->getUserId();
                            $productManufacturer->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $productManufacturer);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $productManufacturerToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $productManufacturerData = $this->buildProductManufacturerData($apiItem, $productManufacturerId);;
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
                                if (!empty($productManufacturerToUpsert)) {
                                    try {
                                        $this->productManufacturerRepository->upsert($productManufacturerToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in manufacturer sync real-time: ' . $e->getMessage(), [
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
            } finally {
                self::$isProcessingProductManufacturerEvent = false;
            }
        }
    }

    public function onProductManufacturerDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductManufacturerEvent) {
                return;
            }
            self::$isProcessingProductManufacturerEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productManufacturerId = $writeResult->getPrimaryKey();
                    if ($productManufacturerId) {
                        $deleteProductManufacturerData = [
                            'shopwareId' => $productManufacturerId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteProductManufacturerData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $productManufacturerData = $this->buildProductManufacturerData($apiItem, $productManufacturerId);
                                    if ($productManufacturerData) {
                                        try {
                                            $this->productManufacturerRepository->upsert($productManufacturerData, $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in manufacturer delete: ' . $e->getMessage(), [
                                                'exception' => $e,
                                                'data' => $deleteProductManufacturerData,
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
                self::$isProcessingProductManufacturerEvent = false;
            }
        }
    }

    public function checkApiAuthentication($odooUrl, $odooToken, $productManufacturer): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $odooUrl,
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
                'apiUrl' => $odooUrl,
                'odooToken' => $odooToken,
            ]);
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function findManufacturerData($productManufacturerId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('media');
        $criteria->addFilter(new EqualsFilter('id', $productManufacturerId));
        return $this->productManufacturerRepository->search($criteria, $event->getContext())->first();
    }

    private function buildProductManufacturerData($apiItem, $productManufacturerId): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_manufacturer_id'])) {
            return [
                "id" => $productManufacturerId,
                'customFields' => [
//                    'shopware_product_brand_id' => $apiItem['odoo_manufacturer_id'],
                    'odoo_manufacturer_id' => $apiItem['odoo_manufacturer_id'],
                    'odoo_manufacturer_error' => null,
                    'shopware_product_brand_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    private function buildProductManufacturerErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'shopware_product_brand_error' => $apiItem['odoo_shopware_error'],
                    'odoo_manufacturer_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
