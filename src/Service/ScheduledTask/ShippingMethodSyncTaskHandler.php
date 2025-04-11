<?php declare(strict_types=1);

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

#[AllowDynamicProperties] #[AsMessageHandler(handles: ShippingMethodSyncTask::class)]
class ShippingMethodSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.shipping.method';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $shippingMethodRepository,
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
            $shippingMethodDataArray = $this->fetchShippingMethodData($context);
            if ($shippingMethodDataArray) {
                foreach ($shippingMethodDataArray as $shippingMethodData) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $shippingMethodData);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $shippingMethodToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $shippingMethodData = $this->buildShippingMethodData($apiItem);
                                if ($shippingMethodData) {
                                    $shippingMethodToUpsert[] = $shippingMethodData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $shippingMethodData = $this->buildShippingMethodErrorData($apiItem);
                                if ($shippingMethodData) {
                                    $shippingMethodToUpsert[] = $shippingMethodData;
                                }
                            }
                        }
                        if (! $shippingMethodToUpsert) {
                            try {
                                $this->shippingMethodRepository->upsert($shippingMethodToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in shipping method sync task', [
                                    'exception' => $e,
                                    'data' => $shippingMethodToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchShippingMethodData($context)
    {
        $shipppingMethoDataSend = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('media');
        $criteria->addAssociation('salesChannels');
        $shippingMethodArray = $this->shippingMethodRepository->search($criteria, $context)->getElements();
        if ($shippingMethodArray) {
            foreach ($shippingMethodArray as $shippingMethod) {
                $customFields = $shippingMethod->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_shipping_method_id', $customFields)) {
                        if ($customFields['odoo_shipping_method_id'] === null || $customFields['odoo_shipping_method_id'] === 0) {
                            $shippingMethodSend[] = $shippingMethod;
                        }
                    } elseif (array_key_exists('odoo_shipping_method_error', $customFields) && $customFields['odoo_shipping_method_error'] === null) {
                        $shippingMethodSend[] = $shippingMethod;
                    }
                } else {
                    $shippingMethodSend[] = $shippingMethod;
                }
            }
        } 
        return $shippingMethodArray;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $shippingMethod): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $shippingMethod,
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

    public function buildShippingMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shipping_method_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_shipping_method_id' => $apiItem['odoo_shipping_method_id'],
                    'odoo_shipping_method_error' => null,
                    'odoo_shipping_method_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildShippingMethodErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_shipping_method_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
