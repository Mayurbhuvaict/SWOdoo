<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PaymentMethodSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.payment.methods';
    private const DELETEMODULE = '/delete/shopware.payment.methods';
    private static $isProcessingPaymentMethodEvent = false;

    public function __construct(
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $paymentMethodRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PaymentEvents::PAYMENT_METHOD_WRITTEN_EVENT => 'onPaymentMethodWritten',
            PaymentEvents::PAYMENT_METHOD_DELETED_EVENT => 'onPaymentMethodDelete',
        ];
    }

    public function onPaymentMethodWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingPaymentMethodEvent) {
                return;
            }
            self::$isProcessingPaymentMethodEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $paymentMethodId = $writeResult->getPrimaryKey();
                    if ($paymentMethodId) {
                        $paymentMethod = $this->findPaymentMethodData($paymentMethodId, $event);
                        if ($paymentMethod) {
                            $userId = $event->getContext()->getSource()->getUserId();
                            $paymentMethod->setExtensions([
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]);
//                            dd($paymentMethod);
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $paymentMethod);
                            if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $paymentMethodToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $paymentMethodData = $this->buildPaymentMethodData($apiItem);
                                        if ($paymentMethodData) {
                                            $paymentMethodToUpsert[] = $paymentMethodData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $paymentMethodData = $this->buildPaymentMethodErrorData($apiItem);
                                        if ($paymentMethodData) {
                                            $paymentMethodToUpsert[] = $paymentMethodData;
                                        }
                                    }
                                }
                                if (! $paymentMethodToUpsert) {
                                    // if (! empty($paymentMethodToUpsert)) {
                                    $this->paymentMethodRepository->upsert($paymentMethodToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingPaymentMethodEvent = false;
            }
        }
    }

    public function findPaymentMethodData($paymentMethodId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('media');
        $criteria->addAssociation('availabilityRule');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('plugin');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('orderTransactions');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('appPaymentMethod');
        $criteria->addFilter(new EqualsFilter('id', $paymentMethodId));
        return $this->paymentMethodRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $paymentMethod): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $paymentMethod,
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

    public function buildPaymentMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_customer_group_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_id' => $apiItem['odoo_customer_group_id'],
                    'odoo_customer_group_error' => null,
                    'odoo_customer_group_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildPaymentMethodErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onPaymentMethodDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        $userId = $event->getContext()->getSource()->getUserId();
        if ($odooUrl !== 'null' && $odooToken) {
            if (self::$isProcessingPaymentMethodEvent) {
                return;
            }
            self::$isProcessingPaymentMethodEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $paymentMethodId = $writeResult->getPrimaryKey();
                    if ($paymentMethodId) {
                        $deletePaymentMethodData = [
                            'shopwareId' => $paymentMethodId,
                            'operation' => $writeResult->getOperation(),
                            'extensions' => [
                                'subscriber' => $userId !== null,
                                'userId' => $userId,
                            ]
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deletePaymentMethodData);
                        if ($apiResponseData && array_key_exists('result', $apiResponseData) && $apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (! $apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $paymentMethodData = $this->buildPaymentMethodErrorData($apiItem);
                                    if ($paymentMethodData) {
                                        $this->paymentMethodRepository->upsert([$paymentMethodData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingPaymentMethodEvent = false;
            }
        }
    }
}
