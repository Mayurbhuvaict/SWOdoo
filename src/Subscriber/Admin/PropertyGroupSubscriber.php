<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use Shopware\Core\Content\Property\PropertyEvents;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PropertyGroupSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/product.attribute';
    private const DELETEMODULE = '/delete/product.attribute';
    private static $isProcessingPropertyGroupEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PropertyEvents::PROPERTY_GROUP_DELETED_EVENT => 'onPropertyDelete',
            PropertyEvents::PROPERTY_GROUP_WRITTEN_EVENT => 'onPropertyWritten'
        ];
    }

    public function onPropertyWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingPropertyGroupEvent) {
                return;
            }
            self::$isProcessingPropertyGroupEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $propertyGroupId = $writeResult->getPrimaryKey();
                    if ($propertyGroupId) {
                        $propertyGroup = $this->findPropertGroupData($propertyGroupId, $context);
                        if ($propertyGroup) {
                            $propertyGroup->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $propertyGroup);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $propertyGroupToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $propertyGroupData = $this->buildPropertGroupData($apiItem);
                                        if ($propertyGroupData) {
                                            $propertyGroupToUpsert[] = $propertyGroupData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $propertyGroupData = $this->buildPropertGroupErrorData($apiItem);
                                        if ($propertyGroupData) {
                                            $propertyGroupToUpsert[] = $propertyGroupData;
                                        }
                                    }
                                }
                                if (!empty($propertyGroupToUpsert)) {
                                    try {
                                        $this->propertGroupRepository->upsert($propertyGroupToUpsert, $context);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in property group sync real-time', [
                                            'exception' => $e,
                                            'data' => $propertyGroupToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingPropertyGroupEvent = false;
            }
        }
    }
    
    public function onPropertyDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingPropertyGroupEvent) {
                return;
            }
            self::$isProcessingPropertyGroupEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $propertyGroupId = $writeResult->getPrimaryKey();
                    if ($propertyGroupId) {
                        $deletePropertyGroupData = [
                            'shopwareId' => $propertyGroupId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deletePropertyGroupData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $propertyGroupData = $this->buildPropertGroupErrorData($apiItem);
                                    if ($propertyGroupData) {
                                        try {
                                            $this->propertGroupRepository->upsert([$propertyGroupData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in property Group Data delete', [
                                                'exception' => $e,
                                                'data' => $deletePropertyGroupData,
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
                self::$isProcessingPropertyGroupEvent = false;
            }
        }
    }

    public function findPropertyGroupData($propertyGroupId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('options');
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('id', $propertyGroupId));
        return $this->propertGroupRepository->search($criteria, $context)->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $propertyGroup): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $propertyGroup,
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

    private function buildPropertyGroupData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_property_group_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_property_group_id' => $apiItem['odoo_property_group_id'],
                    'odoo_property_group_error' => null,
                    'odoo_property_group_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildPropertyGroupErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_property_group_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

}