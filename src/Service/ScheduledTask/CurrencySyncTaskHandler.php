<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: CurrencySyncTask::class)]
class CurrencySyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.currency';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $currencyRepository,
        private readonly LoggerInterface  $logger,
    )
    {
        parent::__construct($scheduledTaskRepository);
        $this->client = new Client();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            $currencyDataArray = $this->fetchCurrencyData($context);
            if ($currencyDataArray) {
                foreach ($currencyDataArray as $currency) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $currency);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $currenciesToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $currencyData = $this->buildCurrencyData($apiItem);
                                if ($currencyData) {
                                    $currenciesToUpsert[] = $currencyData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $currencyData = $this->buildCurrencyErrorData($apiItem);
                                if ($currencyData) {
                                    $currenciesToUpsert[] = $currencyData;
                                }
                            }
                        }
                        if (!empty($currenciesToUpsert)) {
                            try {
                                $this->currencyRepository->upsert($currenciesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in currency task', [
                                    'exception' => $e,
                                    'data' => $currenciesToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchCurrencyData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('countryRoundings');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('salesChannelDefaultAssignments');
//        $criteria->addFilter(new EqualsFilter('updateAT',date()));
//        $criteria->addFilter(new EqualsFilter('customFields.odoo_currency_id', null));
//        $criteria->addFilter(new NotFilter(
//            MultiFilter::CONNECTION_AND,
//            [new EqualsFilter('customFields.odoo_currency_error', null)]
//        ));
        return $this->currencyRepository->search($criteria, $context)->getElements();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $currency): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $currency,
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

    private function buildCurrencyData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_currency_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_currency_id' => $apiItem['odoo_currency_id'],
                    'odoo_currency_error' => null,
                    'odoo_currency_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCurrencyErrorData($apiItem): ?array
    {
//        if (isset($apiItem['id'], $apiItem['odoo_currency_error'])) {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_currency_error' => $apiItem['odoo_shopware_error'],
//                    'odoo_currency_error' => $apiItem['odoo_currency_error'],
                ],
            ];
        }
        return null;
    }
}
