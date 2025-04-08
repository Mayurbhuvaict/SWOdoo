<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CategorySubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.category';
    private const DELETEMODULE = '/delete/shopware.category';
    private static $isProcessingCategoryEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $categoryRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CategoryEvents::CATEGORY_WRITTEN_EVENT => 'onCategoryWritten',
            CategoryEvents::CATEGORY_DELETED_EVENT => 'onCategoryDelete',
        ];
    }

    public function onCategoryWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCategoryEvent) {
                return;
            }
            self::$isProcessingCategoryEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $categoryId = $writeResult->getPrimaryKey();
                    if ($categoryId) {
                        $category = $this->findCategoryData($categoryId, $event);
                        $categoriesToUpsert = [];
                        if ($category) {
                            $userId = $event->getContext()->getSource()->getUserId();
                            $category->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $category);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $categoryData = $this->buildCategoryData($apiItem);
                                        if ($categoryData) {
                                            $categoriesToUpsert[] = $categoryData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $categoryData = $this->buildCategoryErrorData($apiItem);
                                        if ($categoryData) {
                                            $categoriesToUpsert[] = $categoryData;
                                        }
                                    }
                                }
                                if (!empty($categoriesToUpsert)) {
                                    try {
                                        $this->categoryRepository->upsert($categoriesToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in category real-time', [
                                            'exception' => $e,
                                            'data' => $categoriesToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCategoryEvent = false;
            }
        }
    }

    public function findCategoryData($categoryId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('navigationSalesChannels');
        $criteria->addAssociation('footerSalesChannels');
        $criteria->addAssociation('serviceSalesChannels');
        $criteria->addFilter(new EqualsFilter('id', $categoryId));
        return $this->categoryRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $category): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $category,
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

    private function buildCategoryData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_category_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_category_id' => $apiItem['odoo_category_id'],
                    'odoo_category_error' => null,
                    'odoo_category_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCategoryErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_category_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onCategoryDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCategoryEvent) {
                return;
            }
            self::$isProcessingCategoryEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $categoryId = $writeResult->getPrimaryKey();
                    if ($categoryId) {
                        $deleteCategoryData = [
                            'shopwareId' => $categoryId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteCategoryData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if ($apiData && array_key_exists('success', $apiData) &&  !$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $categoryData = $this->buildCategoryErrorData($apiItem);
                                    if ($categoryData) {
                                        try {
                                            $this->categoryRepository->upsert($categoryData, $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in category delete', [
                                                'exception' => $e,
                                                'data' => $deleteCategoryData,
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
                self::$isProcessingCategoryEvent = false;
            }
        }
    }
}
