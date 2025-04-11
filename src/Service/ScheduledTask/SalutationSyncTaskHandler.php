<?php

declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

#[AllowDynamicProperties] #[AsMessageHandler(handles: SalutationSyncTask::class)]
class SalutationSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.salutation';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $salutationRepository,
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
        if ($odooUrl !== 'null') {
            // dd($odooUrl !== 'null', $odooUrl);
            // if ($odooUrl !== 'null' && $odooToken) {
            $salutationDataArray = $this->findSalutationData($context);
            dd($salutationDataArray);
            if ($salutationDataArray) {
                $categoriesToUpsert = [];
                foreach ($salutationDataArray as $salutation) {
                    dd($salutation);
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $salutation);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $salutationData = $this->buildSalutationData($apiItem);
                                if ($salutationData) {
                                    $categoriesToUpsert[] = $salutationData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $salutationData = $this->buildSalutationErrorData($apiItem);
                                if ($salutationData) {
                                    $categoriesToUpsert[] = $salutationData;
                                }
                            }
                        }
                        if (! $categoriesToUpsert) {
                            // if (!empty($categoriesToUpsert)) {
                            try {
                                $this->salutationRepository->upsert($categoriesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in salutation sync task', [
                                    'exception' => $e,
                                    'data' => $categoriesToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function findSalutationData($context): array
    {
        $salutationDataSend = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('orderCustomers');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('newsletterRecipients');
        $salutationDataArray = $this->salutationRepository->search($criteria, $context)->getElements();
        if ($salutationDataArray) {
            foreach ($salutationDataArray as $salutationData) {
                $customFields = $salutationData->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_salutation_id', $customFields)) {
                        if ($customFields['odoo_salutation_id'] === null || $customFields['odoo_salutation_id'] === 0) {
                            $salutationDataSend[] = $salutationData;
                        }
                    } elseif (array_key_exists('odoo_salutation_error', $customFields) && $customFields['odoo_salutation_error'] === null) {
                        $salutationDataSend[] = $salutationData;
                    }
                } else {
                    $salutationDataSend[] = $salutationData;
                }
            }
        } 
        return $salutationDataArray;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $salutation): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $salutation,
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

    public function buildSalutationData($apiItem): ?array
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
}
