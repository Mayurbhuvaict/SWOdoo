<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TagSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.tags';
    private const DELETEMODULE = '/delete/shopware.tags';
    private static $isProcessingTagEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $tagRepository,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'tag.written' => 'onTagWritten',
            'tag.deleted' => 'onTagDelete',
        ];
    }

    public function onTagWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingTagEvent) {
                return;
            }
            self::$isProcessingTagEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $tagId = $writeResult->getPrimaryKey();
                    if ($tagId) {
                        $tagDataArray = $this->findTagData($tagId, $event);
                        if ($tagDataArray) {
                            $tagDataArray->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
//                            dd($tagDataArray);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $tagDataArray);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $tagToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $tagData = $this->buildTagMethodData($apiItem);
                                        if ($tagData) {
                                            $tagToUpsert[] = $tagData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $tagData = $this->buildTagErrorData($apiItem);
                                        if ($tagData) {
                                            $tagToUpsert[] = $tagData;
                                        }
                                    }
                                }
                                if (! $tagToUpsert) {
                                    // if (! empty($tagToUpsert)) {
                                    $this->tagRepository->upsert($tagToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingTagEvent = false;
            }
        }
    }

    public function findTagData($tagId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('products');
        $criteria->addAssociation('media');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('orders');
        $criteria->addAssociation('shippingMethods');
        $criteria->addAssociation('newsletterRecipients');
        $criteria->addAssociation('landingPages');
        $criteria->addAssociation('rules');
        $criteria->addFilter(new EqualsFilter('id', $tagId));
        return $this->tagRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $tagDataArray): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $tagDataArray,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function buildTagMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_tag_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_tag_id' => $apiItem['odoo_tag_id'],
                    'odoo_tag_error' => null,
                    'odoo_tag_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildTagErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_tag_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onTagDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingTagEvent) {
                return;
            }
            self::$isProcessingTagEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $tagId = $writeResult->getPrimaryKey();
                    if ($tagId) {
                        $deleteTagData = [
                            'shopwareId' => $tagId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
//                        dd($deleteTagData);
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteTagData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $tagData = $this->buildTagErrorData($apiItem);
                                    if ($tagData) {
                                        $this->tagRepository->upsert([$tagData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingTagEvent = false;
            }
        }
    }
}
