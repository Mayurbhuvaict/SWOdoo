<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerGroupSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.customer.group';
    private const DELETEMODULE = '/delete/shopware.customer.group';
    private static $isProcessingCustomerGroupEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $customerGroupRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerEvents::CUSTOMER_GROUP_WRITTEN_EVENT => 'onCustomerGroupWritten',
            CustomerEvents::CUSTOMER_GROUP_DELETED_EVENT => 'onCustomerGroupDelete',
        ];
    }

    public function onCustomerGroupWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCustomerGroupEvent) {
                return;
            }
            self::$isProcessingCustomerGroupEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $customerGroupId = $writeResult->getPrimaryKey();
                    if ($customerGroupId) {
                        $customerGroup = $this->findCustomerGroupData($customerGroupId, $event);
                        if ($customerGroup) {
                            $userId = $event->getContext()->getSource()->getUserId();
                            $customerGroup->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $customerGroup);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $customerGroupToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $customerGroupData = $this->buildCustomerGroupData($apiItem);
                                        if ($customerGroupData) {
                                            $customerGroupToUpsert[] = $customerGroupData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $customerGroupData = $this->buildCustomerGroupErrorData($apiItem);
                                        if ($customerGroupData) {
                                            $customerGroupToUpsert[] = $customerGroupData;
                                        }
                                    }
                                }
                                if (! empty($customerGroupToUpsert)) {
                                    $this->customerGroupRepository->upsert($customerGroupToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCustomerGroupEvent = false;
            }
        }
    }

    public function findCustomerGroupData($customerGroupId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('salesChannels');
        $criteria->addFilter(new EqualsFilter('id', $customerGroupId));
        return $this->customerGroupRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $customerGroup): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $customerGroup,
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

    private function buildCustomerGroupData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_customer_group_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_id' => $apiItem['odoo_customer_group_id'],
                    'odoo_customer_group_error' => null,
                    'odoo_customer_group_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCustomerGroupErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onCustomerGroupDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCustomerGroupEvent) {
                return;
            }
            self::$isProcessingCustomerGroupEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $customerGroupId = $writeResult->getPrimaryKey();
                    if ($customerGroupId) {
                        $deleteCustomerGroupData = [
                            'shopwareId' => $customerGroupId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteCustomerGroupData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $customerGroupData = $this->buildCustomerGroupErrorData($apiItem);
                                    if ($customerGroupData) {
                                        $this->customerGroupRepository->upsert([$customerGroupData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingCustomerGroupEvent = false;
            }
        }
    }
}
