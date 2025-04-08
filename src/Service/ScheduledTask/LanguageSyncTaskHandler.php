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

#[AllowDynamicProperties] #[AsMessageHandler(handles: LanguageSyncTask::class)]
class LanguageSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.language';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $languageRepository,
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
            $languageDataArray = $this->fetchLanguageData($context);
            if ($languageDataArray) {
                $languagesToUpsert = [];
                foreach ($languageDataArray as $language) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $language);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $languageData = $this->buildLanguageData($apiItem);
                                if ($languageData) {
                                    $languagesToUpsert[] = $languageData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $languageData = $this->buildLanguageErrorData($apiItem);
                                if ($languageData) {
                                    $languagesToUpsert[] = $languageData;
                                }
                            }
                        }
                        if (! empty($languagesToUpsert)) {
                            try {
                                $this->languageRepository->upsert($languagesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in language sync task', [
                                    'exception' => $e,
                                    'data' => $languagesToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchLanguageData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('salesChannels');
//        $criteria->addFilter(new EqualsFilter('customFields.odoo_language_id', null));
//        $criteria->addFilter(new NotFilter(
//            MultiFilter::CONNECTION_AND,
//            [new EqualsFilter('customFields.odoo_language_error', null)]
//        ));
        return $this->languageRepository->search($criteria, $context)->getElements();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $language): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $language,
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

    private function buildLanguageData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_language_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_language_id' => $apiItem['odoo_language_id'],
                    'odoo_language_error' => null,
                    'odoo_language_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildLanguageErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_language_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
