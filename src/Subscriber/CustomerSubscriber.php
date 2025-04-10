<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber;

use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use GuzzleHttp\Exception\RequestException;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.customer';
    private const DELETEMODULE = '/delete/shopware.customer';
    private static $isProcessingCustomerEvent = false;
   
    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $customerRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }


    public static function getSubscribedEvents(): array
    {
        return [
            // CustomerRegisterEvent::class => 'onRegister',
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'onWrittenCustomerEvent',
            CustomerEvents::CUSTOMER_ADDRESS_WRITTEN_EVENT => 'onWrittenCustomerAddressEvent',
        ];
    }


    public function onWrittenCustomerEvent(EntityWrittenEvent $event): void
    {
        // dd($event->getWriteResults());
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingCustomerEvent) {
                return;
            }
            self::$isProcessingCustomerEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    if (array_key_exists('lastLogin', $writeResult->getPayload()) || array_key_exists('remoteAddress', $writeResult->getPayload())) {
                        return;
                    }
                    $customerId = $writeResult->getPrimaryKey();
                    if ($customerId) {
                        $customerData = $this->findCustomerData($customerId, $context);
                        $customerToUpsert = [];
                        if ($customerData) {
                            $states = $event->getContext()->getStates();
                            $states = $event->getContext()->getStates();
                            $extensions = [
                                'subscriber' => true,
                                'states' => false,
                            ];

                            if (empty($states)) {
                                $extensions['userId'] = 'shopwareAdmin';
                            } elseif (in_array('use-queue-indexing', $states, true)) {
                                $extensions['userId'] = 'shopwareStorefront';
                            } else {
                                $extensions['userId'] = 'unknown';
                            }

                            $customerData->setExtensions($extensions);

                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $customerData);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
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
                                        $this->logger->error('Error in Customer real-time', [
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
            catch (RequestException $e) {
                $this->logger->error('Error while sending customer data to Odoo: ' . $e->getMessage());
            }
            finally {
                self::$isProcessingCustomerEvent = false;
            }
        }
    }


    public function findCustomerData($customerId, $context): ?Entity
    {
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
        $criteria->addAssociation('salutation');
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
        $criteria->addFilter(new EqualsFilter('id',$customerId));
        return $this->customerRepository->search($criteria, $context)->first();
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

    public function buildCustomerData($apiItem): ?array
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
 
    public function buildCustomerErrorData($apiItem): ?array
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

    public function onWrittenCustomerAddressEvent(EntityWrittenEvent $event): void
    {
        $updatedData = $event->getPayloads()[0];
        $this->odooClient->updateCustomerAddress($updatedData, $event->getContext());
    }
}
