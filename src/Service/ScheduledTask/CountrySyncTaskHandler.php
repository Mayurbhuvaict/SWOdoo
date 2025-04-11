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

#[AllowDynamicProperties] #[AsMessageHandler(handles: CountrySyncTask::class)]
class CountrySyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.country';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $countryRepository,
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
            $countryDataArray = $this->fetchCountryData($context);
            if ($countryDataArray) {
                $countriesToUpsert = [];
                dd($countryDataArray);
                foreach ($countryDataArray as $country) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $country);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $countryData = $this->buildCountryData($apiItem);
                                if ($countryData) {
                                    $countriesToUpsert[] = $countryData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $countryData = $this->buildCountryErrorData($apiItem);
                                if ($countryData) {
                                    $countriesToUpsert[] = $countryData;
                                }
                            }
                        }
                        if (! $countriesToUpsert) {
                            try {
                                $this->countryRepository->upsert($countriesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in country sync task', [
                                    'exception' => $e,
                                    'data' => $countriesToUpsert,
                                    'payload' => $country,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchCountryData($context): array
    {
        $countryData = [];
        $criteria = new Criteria();
        $criteria->addAssociation('states');
        $criteria->addAssociation('states.country');
        $criteria->addAssociation('states.translations');
        $criteria->addAssociation('translations');
        // $criteria->addAssociation('countryStates');
        $criteria->addAssociation('customerAddresses');
        $criteria->addAssociation('orderAddresses');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('taxRules');
        $criteria->addAssociation('currencyCountryRoundings');
        $countries = $this->countryRepository->search($criteria, $context)->getElements();
        if ($countries) {
            foreach ($countries as $country) {
                $customFields = $country->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_country_id', $customFields) && $customFields['odoo_country_id'] === null || $customFields['odoo_country_id'] === 0) {
                        $countryData[] = $country;
                    } elseif (array_key_exists('odoo_country_error', $customFields)
                    || $customFields['odoo_country_error'] === null) { 
                        $countryData[] = $country;
                    }
                } else {
                    $countryData[] = $country;
                }
            }
        }
        return $countryData;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $country): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $country,
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

    public function buildCountryData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_country_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_id' => $apiItem['odoo_country_id'],
                    'odoo_country_error' => null,
                    'odoo_country_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildCountryErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_country_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
