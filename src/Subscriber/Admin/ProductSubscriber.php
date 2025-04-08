<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Uuid\Uuid;

class ProductSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/product.template';
    private const DELETEMODULE = '/delete/product.template';
    private static $isProcessingProductEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $productMediaRepository,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly EntityRepository $tagsRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onWrittenProductEvent',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onDeleteProductEvent',
        ];
    }
    public function onWrittenProductEvent(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductEvent) {
                return;
            }
            self::$isProcessingProductEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productId = $writeResult->getPrimaryKey();
                    $payloadData = $writeResult->getPayload();
                    $manufacturerId = $payloadData['manufacturerId'] ?? null;
                    $taxId = $payloadData['taxId'] ?? null;
                    $coverId = $payloadData['coverId'] ?? null;
                    file_put_contents("ProductProcessLog.txt", date("Y-m-d H:i:s") . " - Processing Product ID: $productId\n", FILE_APPEND);
                    if ($productId) {
                        $productDetails = $this->findProductData($productId, $event);
                        if ($productDetails->getParentId()) {
                            $manufacturerId = $productDetails->getManufacturerId() ?? null;
                            $taxId = $productDetails->getTaxId() ?? null;
                            $parentData = $this->findProductData($productDetails->getParentId(), $event);
                            $coverId = $productDetails->getcoverId() ?? null;
                            if ($parentData) {
                                $productDetails->assign(['parent' => $parentData]);
                            }
                            if ($productDetails->getOptions()) {
                                $options = $productDetails->getOptions()->getElements();
                                $optionData = [];
                                foreach ($options as $option) {
                                    $optionData[] = $option->getId();
                                }
                                if (! empty($optionData)) {
                                    $productDetails->assign(['optionIds' => $optionData]);
                                }
                            }
                        }
                        if (! $productDetails->getManufacturer() && $manufacturerId) {
                            $manufacturer = $this->getManufacturerData($manufacturerId, $event);
                            if ($manufacturer) {
                                $productDetails->assign(['manufacturer' => $manufacturer]);
                            }
                        }

                        if ($taxId) {
                            $tax = $this->getTaxData($taxId, $event);
                            if ($tax) {
                                $productDetails->assign(['tax' => $tax]);
                            }
                        }

                        if (! $productDetails->getCategories()) {
                            $categoryIds = $this->getCategoryIdsByProductId($productId);
                            if ($categoryIds) {
                                $categoryData = $this->getCategoriesData($categoryIds, $event->getContext());
                                $productDetails->assign([
                                    'categoryIds' => $categoryIds,
                                    'categoryTree' => $categoryIds,
                                ]);
                                $productDetails->assign([
                                    'categories' => $categoryData,
                                ]);
                            } else {
                                $productDetails->assign([
                                    'categoryIds' => []
                                ]);
                            }
                        } else {
                            $categoryIds = $this->getCategoryIdsByProductId($productId);
                            if($categoryIds) {
                                $categoryData = $this->getCategoriesData($categoryIds, $event->getContext());
                                $productDetails->assign([
                                    'categoryIds' => $categoryIds,
                                    'categoryTree' => $categoryIds,
                                ]);
                            } else {
                                $productDetails->assign([
                                    'categoryIds' => []
                                ]);
                            }
                            if (! empty($categoryData)) {
                                $productDetails->assign([
                                    'categories' => $categoryData,
                                ]);
                            }
                        }

                        if (! $productDetails->getCover() && $coverId) {
                            $cover = $this->getCoverById($coverId, $event->getContext());
                            if ($cover) {
                                $productDetails->assign([
                                    'cover' => $cover,
                                ]);
                            }
                        }

                        if (! $productDetails->getDeliveryTime() && $productDetails->getDeliveryTimeId()) {
                            $deliveryTime = $this->getDeliveryTimeById($productDetails->getDeliveryTimeId(), $event->getContext());
                            if ($deliveryTime) {
                                $productDetails->assign([
                                    'deliveryTime' => $deliveryTime,
                                ]);
                            }
                        }

                        if (! $productDetails->getTagIds()) {
                            $tagIds = $this->getTagsIdByProductId($productId);
                            if (! empty($tagIds)) {
                                $productDetails->assign([
                                    'tagIds' => $tagIds,
                                ]);
                                $tagIdsData = $this->getTagsData($tagIds, $event->getContext());
                                if (! empty($tagIdsData)) {
                                    $productDetails->assign([
                                        'tags' => $tagIdsData,
                                    ]);
                                }
                            }
                        }
                        else{
                            $tagIdsData = $this->getTagsData($productDetails->getTagIds(), $event->getContext());
                            if (! empty($tagIdsData)) {
                                $productDetails->assign([
                                    'tags' => $tagIdsData,
                                ]);
                            }
                        }
                        $children = $this->getChildrenByProductId($productId, $event->getContext());
                        if (! empty($children->getEntities())) {
                            foreach ($children->getEntities() as $child) {

                                if (! $child->getCategories()) {
                                    $categoryIds = $this->getCategoryIdsByProductId($productId);
                                    if ($categoryIds) {
                                        $categoryData = $this->getCategoriesData($categoryIds, $event->getContext());
                                        $child->assign([
                                            'categoryIds' => $categoryIds,
                                            'categoryTree' => $categoryIds,
                                        ]);
                                        $child->assign([
                                            'categories' => $categoryData,
                                        ]);
                                    } else {
                                        $child->assign([
                                            'categoryIds' => []
                                        ]);
                                    }
                                } else {
                                    $categoryIds = $this->getCategoryIdsByProductId($productId);
                                    if($categoryIds) {
                                        $categoryData = $this->getCategoriesData($categoryIds, $event->getContext());
                                        $child->assign([
                                            'categoryIds' => $categoryIds,
                                            'categoryTree' => $categoryIds,
                                        ]);
                                    } else {
                                        $child->assign([
                                            'categoryIds' => []
                                        ]);
                                    }
                                    if (! empty($categoryData)) {
                                        $child->assign([
                                            'categories' => $categoryData,
                                        ]);
                                    }
                                }
                            }
                            $productDetails->assign([
                                'childCount' => count($productDetails->getChildren()),
                                'children' => $children->getEntities(),
                            ]);
                        }
                        file_put_contents(
                            "ProductProcessLog.txt",
                            date("Y-m-d H:i:s") . " - Final Product Data:\n" . json_encode($productDetails, JSON_PRETTY_PRINT) . "\n",
                            FILE_APPEND
                        );
                        $userId = $event->getContext()->getSource()->getUserId();
                        $productDetails->setExtensions([
                            'subscriber' => $userId !== null,
                            'userId' => $userId,
                        ]);
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $productDetails);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            $productToUpsert = [];
                            if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $productData = $this->buildProductData($apiItem);
                                    if ($productData) {
                                        $productToUpsert[] = $productData;
                                    }
                                }
                            } else {
                                foreach ($apiData['data'] ?? [] as $apiItem) {
                                    $productData = $this->buildProductErrorData($apiItem);
                                    if ($productData) {
                                        $productToUpsert[] = $productData;
                                    }
                                }
                            }
                            if (! empty($productToUpsert)) {
                                $this->productRepository->upsert($productToUpsert, $context);
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingProductEvent = false;
                file_put_contents("ProductProcessLog.txt", date("Y-m-d H:i:s") . " - Finally processing Product ID: $productId \n", FILE_APPEND);
            }
        }
    }

    public function findProductData($productId, $event): ?Entity
    {
        $event->getContext()->setConsiderInheritance(true);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $productId));
        $criteria->addAssociation('translations');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('visibilities.salesChannel');
        $criteria->addAssociation('configuratorSettings.option');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('manufacturer.translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('prices.rule');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('tax.rules');
        $criteria->addAssociation('searchKeywords');
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('unit');
        $criteria->addAssociation('deliveryTime');
        $criteria->addAssociation('deliveryTime.translations');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('properties');
        $criteria->addAssociation('properties.translations');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('properties.group.translations');
        $criteria->addAssociation('options');
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('options.group.translations');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('category_ids');
        $criteria->addAssociation('categories.translations');
        $criteria->addAssociation('categoriesRo');
        $criteria->addAssociation('categoriesRo.translations');
        $criteria->addAssociation('mainCategories');
        $criteria->addAssociation('streams');
        $criteria->addAssociation('streams.categories');
        $criteria->addAssociation('streams.categories.translations');
        $criteria->addAssociation('configuratorSettings');
        $criteria->addAssociation('children');
        $criteria->addAssociation('children.media');
        $criteria->addAssociation('children.cover');
        $criteria->addAssociation('children.options');
        $criteria->addAssociation('children.options.translations');
        $criteria->addAssociation('children.options.group');
        $criteria->addAssociation('children.options.group.translations');
        $criteria->addAssociation('children.translations');
        $criteria->addAssociation('children.configuratorSettings');
        $criteria->addAssociation('price');
        $criteria->addAssociation('crossSellings');
        $criteria->addAssociation('crossSellingAssignedProducts');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('wishlists');
        $criteria->addAssociation('customFieldSets');
        return $this->productRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $product): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $product,
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

    public function buildProductData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_product_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_product_id' => $apiItem['odoo_product_id'],
                    'odoo_product_error' => null,
                    'odoo_product_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    public function buildProductErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_product_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onDeleteProductEvent(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductEvent) {
                return;
            }
            self::$isProcessingProductEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productId = $writeResult->getPrimaryKey();
                    if ($productId) {
                        $deleteProductData = [
                            'shopwareId' => $productId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteProductData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!  $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $productData = $this->buildProductErrorData($apiItem);
                                    if ($productData) {
                                        $this->productRepository->upsert([$productData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingProductEvent = false;
            }
        }
    }

    public function getManufacturerData(string $manufacturerId, EntityWrittenEvent $event)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $manufacturerId));
        $criteria->addAssociation('translations');
        return $this->manufacturerRepository->search($criteria, $event->getContext())->first();
    }

    public function getTaxData(string $taxId, EntityWrittenEvent $event)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $taxId));
        $criteria->addAssociation('rules');
        return $this->taxRepository->search($criteria, $event->getContext())->first();
    }

    public function getCategoryIdsByProductId($productId): array
    {
        $productIdBinary = Uuid::fromHexToBytes($productId);
        $sql = "SELECT category_id FROM product_category WHERE product_id = :productId";
        $categoryIdsBinary = $this->connection->fetchFirstColumn($sql, ['productId' => $productIdBinary]);
        return array_map(fn($id) => Uuid::fromBytesToHex($id), $categoryIdsBinary);
    }

    public function getCategoriesData($categoryIds, $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $categoryIds));
        $criteria->addAssociation('translations');
        return $this->categoryRepository->search($criteria, $context)->getEntities();
    }

    public function getChildrenByProductId(string $productId, $context): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $productId));
        $criteria->addAssociation('translations');
        $criteria->addAssociation('translations.language');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('visibilities.salesChannel');
        $criteria->addAssociation('configuratorSettings.option');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('manufacturer.translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('prices.rule');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('tax.rules');
        $criteria->addAssociation('searchKeywords');
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('unit');
        $criteria->addAssociation('deliveryTime');
        $criteria->addAssociation('deliveryTime.translations');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('properties');
        $criteria->addAssociation('properties.translations');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('properties.group.translations');
        $criteria->addAssociation('options');
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('options.group.translations');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('category_ids');
        $criteria->addAssociation('categories.translations');
        $criteria->addAssociation('categoriesRo');
        $criteria->addAssociation('categoriesRo.translations');
        $criteria->addAssociation('mainCategories');
        $criteria->addAssociation('streams');
        $criteria->addAssociation('streams.categories');
        $criteria->addAssociation('streams.categories.translations');
        $criteria->addAssociation('configuratorSettings');
        $criteria->addAssociation('children');
        $criteria->addAssociation('children.categories');
        $criteria->addAssociation('children.category_ids');
        $criteria->addAssociation('children.visibilities');
        $criteria->addAssociation('children.media');
        $criteria->addAssociation('children.cover');
        $criteria->addAssociation('children.translations');
        $criteria->addAssociation('children.configuratorSettings');
        $criteria->addAssociation('price');
        $criteria->addAssociation('crossSellings');
        $criteria->addAssociation('crossSellingAssignedProducts');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('wishlists');
        $criteria->addAssociation('customFieldSets');
        return $this->productRepository->search($criteria, $context);
    }

    public function getCoverById(string $coverId, $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $coverId));
        $criteria->addAssociation('media');
        return $this->productMediaRepository->search($criteria, $context)->first();
    }

    public function getDeliveryTimeById(string $deliveryTimeId, $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $deliveryTimeId));
        $criteria->addAssociation('translations');
        return $this->deliveryTimeRepository->search($criteria, $context)->first();
    }

    public function getTagsIdByProductId($productId): array
    {
        $productIdBinary = Uuid::fromHexToBytes($productId);
        $sql = "SELECT tag_id FROM product_tag WHERE product_id = :productId";
        $tagIdsBinary = $this->connection->fetchFirstColumn($sql, ['productId' => $productIdBinary]);
        return array_map(fn($id) => Uuid::fromBytesToHex($id), $tagIdsBinary);
    }

    public function getTagsData(array $tagIds, $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $tagIds));
        $criteria->addAssociation('translations');
        return $this->tagsRepository->search($criteria, $context)->getEntities();
    }

}
