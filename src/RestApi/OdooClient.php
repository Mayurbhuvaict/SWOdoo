<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\RestApi;

use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use ICTECHOdooShopwareConnector\Service\CustomerService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class OdooClient
{
    public const MM_ORDER_CREATE_ENDPOINT = '/shop/create_sale_order_from_shopware';
    public const MM_ENDPOINT = '/shop/contact_create_from_shopware';
    public const MM_STATUS_ENDPOINT = '/odoo/states/';

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly EntityRepository $customerRepository,
        private readonly PluginConfig $pluginConfig,
    ) {
        $this->client = new Client();
    }

    public function importCustomer($customer, $context): void
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $customerUrl = $odooUrl . self::MM_ENDPOINT;
        $customerPayload = $this->customerService->generateCustomerPayload($customer, $context);
        $response = $this->client->post(
            $customerUrl,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $customerPayload
            ]
        );

        $apiResponse = json_decode($response->getBody()->getContents(), true)['result'];
        if ($apiResponse['code'] === 200 && $apiResponse['success']) {
            foreach ($apiResponse['customer_info'] as $customerInfo) {
                $data = [
                    'id' => $customerInfo['shopware_customer_id'],
                    'customFields' => [
                        'odoo_customer_id' => $customerInfo['odoo_id'],
                        'odoo_customer_sync_status' => true,
                    ]
                ];
                $this->customerRepository->upsert([$data], $context);
            }
        } else {
            $data = [
                'id' => $customer->getId(),
                'customFields' => [
                    'odoo_customer_id' => null,
                    'odoo_customer_sync_status' => false,
                ]
            ];
            $this->customerRepository->upsert([$data], $context);
        }
    }

    public function importOrder($orderPayload, $context): void
    {
        $orderData = [];
        $orderData[] = $orderPayload;
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);

        if ($odooUrl == "null") {
            return;
        }
        $this->client->post(
            $odooUrl . self::MM_ORDER_CREATE_ENDPOINT,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $orderData
            ]
        );
    }

    public function updateCustomer($updateCustomerData, $context): void
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $updateCustomerData = $this->customerService->getNewUpdatedCustomer($updateCustomerData, $context);
        $this->client->put(
            $odooUrl . self::MM_ENDPOINT,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $updateCustomerData
            ]
        );
    }

    public function updateCustomerAddress($updateCustomerData, $context): void
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $updateCustomerDataMain = [];
        $updateCustomerDataPayload = $this->customerService->getNewUpdatedCustomer($updateCustomerData, $context);

        $updateCustomerDataMain['customerAddress'] = $updateCustomerDataPayload;
        $this->client->put(
            $odooUrl . self::MM_ENDPOINT,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $updateCustomerDataMain
            ]
        );
    }

    public function orderStatusChange($orderStatusData, $context): void
    {
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $this->client->post(
            $odooUrl . self::MM_STATUS_ENDPOINT,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $orderStatusData
            ]
        );
    }
}
