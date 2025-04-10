<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;


class SalutationSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.salutation';
    private const DELETEMODULE = '/delete/shopware.salutation';
    private static $isProcessingSalutationEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $salutationRepository,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'salutation.written' => 'onSalutationWritten',
            'salutation.deleted' => 'onSalutationDelete',
        ];
    }

    public function onSalutationWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingSalutationEvent) {
                return;
            }
            self::$isProcessingSalutationEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $salutationId = $writeResult->getPrimaryKey();
                    if ($salutationId) {
                        $salutationDataArray = $this->findSalutationData($salutationId, $event);
                        if ($salutationDataArray) {
                            $salutationDataArray->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
//                            dd($salutationDataArray);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $salutationDataArray);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $salutationToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $salutationData = $this->buildSalutationMethodData($apiItem);
                                        if ($salutationData) {
                                            $salutationToUpsert[] = $salutationData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $salutationData = $this->buildSalutationErrorData($apiItem);
                                        if ($salutationData) {
                                            $salutationToUpsert[] = $salutationData;
                                        }
                                    }
                                }
                                if (! $salutationToUpsert) {
                                    // if (! empty($salutationToUpsert)) {
                                    $this->salutationRepository->upsert($salutationToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingSalutationEvent = false;
            }
        }
    }

    public function findSalutationData($salutationId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('orderCustomers');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('newsletterRecipients');
        $criteria->addFilter(new EqualsFilter('id', $salutationId));
        return $this->salutationRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $salutationDataArray): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $salutationDataArray,
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

    public function buildSalutationMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_salutation_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_salutation_id' => $apiItem['odoo_salutation_id'],
                    'odoo_salutation_error' => null,
                    'odoo_salutation_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildSalutationErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_salutation_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onSalutationDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingSalutationEvent) {
                return;
            }
            self::$isProcessingSalutationEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $salutationId = $writeResult->getPrimaryKey();
                    if ($salutationId) {
                        $deleteSalutationData = [
                            'shopwareId' => $salutationId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
//                        dd($deleteSalutationData);
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteSalutationData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $salutationData = $this->buildSalutationErrorData($apiItem);
                                    if ($salutationData) {
                                        $this->salutationRepository->upsert([$salutationData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingSalutationEvent = false;
            }
        }
    }
}
