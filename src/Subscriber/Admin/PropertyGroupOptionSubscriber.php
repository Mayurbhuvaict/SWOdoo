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

class PropertyGroupOptionSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/product.attribute.value';
    private const DELETEMODULE = '/delete/product.attribute.value';
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
            PropertyEvents::PROPERTY_GROUP_OPTION_WRITTEN_EVENT => 'onPropertyGroupOptionWritten',
            PropertyEvents::PROPERTY_GROUP_OPTION_DELETED_EVENT => 'onPropertyGroupOptionDelete'
        ];
    }

    public function onPropertyGroupOptionWritten(EntityWrittenEvent $event): void
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
                        $propertyGroup = $this->findPropertyGroupOptionData($propertyGroupId, $context);
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
                                        $this->propertyGroupRepository->upsert($propertyGroupToUpsert, $context);
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
    
    public function onPropertyGroupOptionDelete(EntityWrittenEvent $event): void
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
                        $deletePropertyGroupOptionData = [
                            'shopwareId' => $propertyGroupId,
                            'operation' => $writeResult->getOperation(),
                            "extensions" => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deletePropertyGroupOptionData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $propertyGroupOptionData = $this->buildPropertGroupErrorData($apiItem);
                                    if ($propertyGroupOptionData) {
                                        try {
                                            $this->propertyGroupRepository->upsert([$propertyGroupOptionData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in property Group Data delete', [
                                                'exception' => $e,
                                                'data' => $deletePropertyGroupOptionData,
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

    public function findPropertyGroupOptionData($propertyGroupId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('options');
        $criteria->addAssociation('translations');
        $criteria->addAssociation('media');
        $criteria->addAssociation('group');
        $criteria->addAssociation('group.translations');
        $criteria->addAssociation('group.options');
        $criteria->addFilter(new EqualsFilter('id', $propertyGroupId));
        return $this->propertyGroupRepository->search($criteria, $context)->first();
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