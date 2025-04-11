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

#[AllowDynamicProperties] #[AsMessageHandler(handles: CategorySyncTask::class)]
class CategorySyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.category';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $categoryRepository,
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
            $categoryDataArray = $this->fetchCategoryData($context);
            if ($categoryDataArray) {
                $categoriesToUpsert = [];
                foreach ($categoryDataArray as $category) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $category);
                    if ($apiResponseData['result']) {
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
                        if (! $categoriesToUpsert) {
                            try {
                                $this->categoryRepository->upsert($categoriesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in category sync task', [
                                    'exception' => $e,
                                    'data' => $categoriesToUpsert,
                                    'payload' => $category,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchCategoryData($context): array
    {
        $categoryData = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('navigationSalesChannels');
        $criteria->addAssociation('footerSalesChannels');
        $criteria->addAssociation('serviceSalesChannels');
        $categories = $this->categoryRepository->search($criteria, $context)->getElements();
        if ($categories) {
            foreach ($categories as $category) {
                $customFields = $category->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_category_id', $customFields) && $customFields['odoo_category_id'] === null || $customFields['odoo_category_id'] === 0 ) {
                        $categoryData[] = $category;
                    } elseif (array_key_exists('odoo_category_error', $customFields)
                    || $customFields['odoo_category_error'] === null) { 
                        $categoryData[] = $category;
                    }
                } else {
                    $categoryData[] = $category;
                }
            }
        }
        return $categoryData;
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

    public function buildCategoryData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_category_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_category_id' => $apiItem['odoo_category_id'],
                    'odoo_category_error' => null,
                    'odoo_category_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCategoryErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_category_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
