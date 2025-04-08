<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: SalesChannelSyncTask::class)]
class SalesChannelSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.sales.channel';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $salesChannelRepository,
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
            $salesChannelDataArray = $this->fetchSalesChannelData($context);
            if ($salesChannelDataArray) {
                foreach ($salesChannelDataArray as $salesChannel) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $salesChannel);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $salesChannelsToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $salesChannelData = $this->buildSalesChannelData($apiItem);
                                if ($salesChannelData) {
                                    $salesChannelsToUpsert[] = $salesChannelData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $salesChannelData = $this->buildSalesChannelErrorData($apiItem);
                                if ($salesChannelData) {
                                    $salesChannelsToUpsert[] = $salesChannelData;
                                }
                            }
                        }
                        if (!empty($salesChannelsToUpsert)) {
                            try {
                                $this->salesChannelRepository->upsert($salesChannelsToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in sales-channel task', [
                                    'exception' => $e,
                                    'data' => $salesChannelsToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchSalesChannelData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('navigationSalesChannels');
        $criteria->addAssociation('footerSalesChannels');
        $criteria->addAssociation('serviceSalesChannels');
//        $criteria->addFilter(new EqualsFilter('customFields.odoo_sales_channel_id', null));
//        $criteria->addFilter(new NotFilter(
//            MultiFilter::CONNECTION_AND,
//            [new EqualsFilter('customFields.odoo_sales_channel_error', null)]
//        ));
        return $this->salesChannelRepository->search($criteria, $context)->getElements();
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
}
