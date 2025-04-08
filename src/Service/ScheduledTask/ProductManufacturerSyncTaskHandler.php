<?php declare(strict_types=1);

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
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $productManufacturerRepository,
        private readonly LoggerInterface  $logger,
    )
    {
        parent::__construct($scheduledTaskRepository);
        $this->client = new Client();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
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
                    }
                    if (!empty($productManufacturerToUpsert)) {
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
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('media');
        return $this->productManufacturerRepository->search($criteria, $context)->getElements();
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

    private function buildProductManufacturerData($apiItem, $productManufacturerId): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_manufacturer_id'])) {
            return [
                "id" => $productManufacturerId,
                'customFields' => [
                    'odoo_manufacturer_id' => $apiItem['odoo_manufacturer_id'],
                    'odoo_manufacturer_error' => null,
                    'odoo_manufacturer_update_time' => date('Y-m-d H:i'),
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
                    'odoo_manufacturer_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
