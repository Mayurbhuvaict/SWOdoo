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

#[AllowDynamicProperties] #[AsMessageHandler(handles: CountryStateSyncTask::class)]
class CountryStateSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.state';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $countryStateRepository,
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
            $countryStateDataArray = $this->fetchCountryStateData($context);
            if ($countryStateDataArray) {
                $countryStateToUpsert = [];
                dd($countryStateDataArray);
                foreach ($countryStateDataArray as $countryState) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $countryState);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $countryStateData = $this->buildCountryStateData($apiItem);
                                if ($countryStateData) {
                                    $countryStateToUpsert[] = $countryStateData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $countryStateData = $this->buildCountryStateErrorData($apiItem);
                                if ($countryStateData) {
                                    $countryStateToUpsert[] = $countryStateData;
                                }
                            }
                        }
                        if (! $countryStateToUpsert) {
                            try {
                                $this->countryStateRepository->upsert($countryStateToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in country state sync task', [
                                    'exception' => $e,
                                    'data' => $countryStateToUpsert,
                                    'payload' => $countryState,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchCountryStateData($context): array
    {
        $countryStateData = [];
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('translations.language');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('country');
        $criteria->addAssociation('country.states');
        $criteria->addAssociation('country.states.translations');
        $criteria->addAssociation('country.translations');
        $criteria->addAssociation('country.translations.language');
        $criteria->addAssociation('countryStates');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('taxRules');
        $criteria->addAssociation('currencyCountryRoundings');
        $countryState = $this->countryStateRepository->search($criteria, $context)->getElements();
        if ($countryState) {
            foreach ($countryState as $countryState) {
                $customFields = $countryState->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_country_state_id', $customFields) && $customFields['odoo_country_state_id'] === null || $customFields['odoo_country_state_id'] === 0) {
                        $countryStateData[] = $countryState;
                    } elseif (array_key_exists('odoo_country_state_error', $customFields)
                    || $customFields['odoo_country_state_error'] === null) { 
                        $countryStateData[] = $countryState;
                    }
                } else {
                    $countryStateData[] = $countryState;
                }
            }
        }
        return $countryStateData;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $countryState): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $countryState,
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

    public function buildCountryStateData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_country_state_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_state_id' => $apiItem['odoo_country_state_id'],
                    'odoo_country_state_error' => null,
                    'odoo_country_state_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCountryStateErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_state_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
