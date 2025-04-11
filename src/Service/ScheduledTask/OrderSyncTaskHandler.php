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

#[AllowDynamicProperties] #[AsMessageHandler(handles: OrderSyncTask::class)]
class OrderSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/res.partner';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $orderRepository,
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
        if ($odooUrlData !== 'null' && $odooUrl !== 'null' && $odooToken) {
            $orderDataArray = $this->fetchOrderData($context);
        
            if ($orderDataArray) {
                foreach ($orderDataArray as $orderData) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $orderData);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $orderToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $orderData = $this->buildOrderData($apiItem);
                                if ($orderData) {
                                    $orderToUpsert[] = $orderData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $orderData = $this->buildOrderErrorData($apiItem);
                                if ($orderData) {
                                    $orderToUpsert[] = $orderData;
                                }
                            }
                        }
                        if (! $orderToUpsert) {
                            try {
                                $this->orderRepository->upsert($orderToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in order sync task', [
                                    'exception' => $e,
                                    'data' => $orderToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchOrderData($context): array
    {
        $orderDataSend = [];
        $criteria = new Criteria();
        $criteria->addAssociation('group');
        $criteria->addAssociation('defaultPaymentMethod');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('language');
        $criteria->addAssociation('lastPaymentMethod');
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('activeBillingAddress');
        $criteria->addAssociation('defaultShippingAddress');
        $criteria->addAssociation('activeShippingAddress');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('orderOrders');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('promotions');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('recoveryOrder');
        $criteria->addAssociation('tagIds');
        $criteria->addAssociation('requestedGroupId');
        $criteria->addAssociation('requestedGroup');
        $criteria->addAssociation('boundSalesChannelId');
        $criteria->addAssociation('accountType');
        $criteria->addAssociation('boundSalesChannel');
        $criteria->addAssociation('wishlists');
        $orderArray = $this->orderRepository->search($criteria, $context)->getElements();
        if ($orderArray) {
            foreach ($orderArray as $order) {
                $customFields = $order->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_order_id', $customFields)) {
                        if ($customFields['odoo_order_id'] === null || $customFields['odoo_order_id'] === 0) {
                            $orderDataSend[] = $order;
                        }
                    } elseif (array_key_exists('odoo_order_error', $customFields) && $customFields['odoo_order_error'] === null) {
                        $orderDataSend[] = $order;
                    }
                } else {
                    $orderDataSend[] = $order;
                }
            }
        }
        return $orderDataSend;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $order): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $order,
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

    public function buildOrderData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_order_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_order_id' => $apiItem['odoo_order_id'],
                    'odoo_order_error' => null,
                    'odoo_order_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildOrderErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_order_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_order_error' => $apiItem['odoo_order_error'],
                ],
            ];
        }
        return null;
    }
}
