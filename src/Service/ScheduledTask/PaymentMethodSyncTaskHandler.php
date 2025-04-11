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

#[AllowDynamicProperties] #[AsMessageHandler(handles: PaymentMethodSyncTask::class)]
class PaymentMethodSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.payment.methods';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $paymentMethodRepository,
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
        if ($odooUrl !== 'null' && $odooToken) {
            $paymentMethodDataArray = $this->fetchPaymentMethodData($context);
            if ($paymentMethodDataArray) {
                $paymentMethodsToUpsert = [];
                foreach ($paymentMethodDataArray as $paymentMethod) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $paymentMethod);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $paymentMethodData = $this->buildPaymentMethodData($apiItem);
                                if ($paymentMethodData) {
                                    $paymentMethodsToUpsert[] = $paymentMethodData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $paymentMethodData = $this->buildPaymentMethodErrorData($apiItem);
                                if ($paymentMethodData) {
                                    $paymentMethodsToUpsert[] = $paymentMethodData;
                                }
                            }
                        }
                        if (! $paymentMethodsToUpsert) {
                            try {
                                $this->paymentMethodRepository->upsert($paymentMethodsToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in payment method sync task', [
                                    'exception' => $e,
                                    'data' => $paymentMethodsToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchPaymentMethodData($context): array
    {
        $paymentMethodDataSend = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('media');
        $criteria->addAssociation('availabilityRule');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('plugin');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('orderTransactions');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('appPaymentMethod');
        $paymentMethodDataArray = $this->paymentMethodRepository->search($criteria, $context)->getElements();
        if ($paymentMethodDataArray) {
            foreach ($paymentMethodDataArray as $paymentMethodData) {
                
                $customFields = $paymentMethodData->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_payment_method_id', $customFields)) {
                        if ($customFields['odoo_payment_method_id'] === null || $customFields['odoo_payment_method_id'] === 0) {
                            $paymentMethodDataSend[] = $paymentMethodData;
                        }
                    } elseif (array_key_exists('odoo_payment_method_error', $customFields) && $customFields['odoo_payment_method_error'] === null) {
                        $paymentMethodDataSend[] = $paymentMethodData;
                    }
                } else {
                    $paymentMethodDataSend[] = $paymentMethodData;
                }
            }
        }
        return $paymentMethodDataSend;
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
        if (isset($apiItem['id'], $apiItem['odoo_payment_method_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_payment_method_id' => $apiItem['odoo_payment_method_id'],
                    'odoo_payment_method_error' => null,
                    'odoo_payment_method_update_time' => date('Y-m-d H:i'),
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
                    'odoo_payment_method_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
