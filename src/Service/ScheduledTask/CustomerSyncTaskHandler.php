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

#[AllowDynamicProperties] #[AsMessageHandler(handles: CustomerSyncTask::class)]
class CustomerSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/res.partner';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $customerRepository,
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
            $customerDataArray = $this->fetchCustomerData($context);
            if ($customerDataArray) {
                foreach ($customerDataArray as $customerData) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $customerData);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $customerToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $customerData = $this->buildCustomerData($apiItem);
                                if ($customerData) {
                                    $customerToUpsert[] = $customerData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $customerData = $this->buildCustomerErrorData($apiItem);
                                if ($customerData) {
                                    $customerToUpsert[] = $customerData;
                                }
                            }
                        }
                        if (! $customerToUpsert) {
                            try {
                                $this->customerRepository->upsert($customerToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in customer sync task', [
                                    'exception' => $e,
                                    'data' => $customerToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchCustomerData($context): array
    {
        $customerDataSend = [];
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
        $criteria->addAssociation('orderCustomers');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('promotions');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('recoveryCustomer');
        $criteria->addAssociation('tagIds');
        $criteria->addAssociation('requestedGroupId');
        $criteria->addAssociation('requestedGroup');
        $criteria->addAssociation('boundSalesChannelId');
        $criteria->addAssociation('accountType');
        $criteria->addAssociation('boundSalesChannel');
        $criteria->addAssociation('wishlists');
        $customerArray = $this->customerRepository->search($criteria, $context)->getElements();
        if ($customerArray) {
            foreach ($customerArray as $customer) {
                $customFields = $customer->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_customer_id', $customFields)) {
                        if ($customFields['odoo_customer_id'] === null || $customFields['odoo_customer_id'] === 0) {
                            $customerDataSend[] = $customer;
                        }
                    } elseif (array_key_exists('odoo_customer_error', $customFields) && $customFields['odoo_customer_error'] === null) {
                        $customerDataSend[] = $customer;
                    }
                } else {
                    $customerDataSend[] = $customer;
                }
            }
        }
        return $customerDataSend;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $customer): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $customer,
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

    public function buildCustomerData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_customer_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_id' => $apiItem['odoo_customer_id'],
                    'odoo_customer_error' => null,
                    'odoo_customer_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCustomerErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_customer_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_error' => $apiItem['odoo_customer_error'],
                ],
            ];
        }
        return null;
    }
}
